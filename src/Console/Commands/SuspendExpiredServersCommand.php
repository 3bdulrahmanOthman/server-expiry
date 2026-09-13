<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Console\Commands;

use App\Enums\ServerState;
use App\Enums\SuspendAction;
use App\Models\Server;
use App\Services\Servers\SuspensionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use SquadronStrike\ServerExpiry\Application\Services\ExpirationService;
use SquadronStrike\ServerExpiry\Notifications\ServerExpiredNotification;
use SquadronStrike\ServerExpiry\Support\SuspensionContext;
use Throwable;

class SuspendExpiredServersCommand extends Command
{
    protected $signature = 'pelican:suspend-expired-servers {--grace-hours= : Override default grace period hours}';

    protected $description = 'Checks for servers past their expires_at date and automatically suspends them via the Wings API.';

    public function __construct(
        private readonly SuspensionService $suspensionService,
        private readonly ExpirationService $expirationService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->expirationService->isAutoSuspendEnabled()) {
            $this->warn('Auto-suspend is disabled (SERVER_EXPIRY_AUTO_SUSPEND=false). Aborting.');

            return self::SUCCESS;
        }

        $this->info('Starting expired servers scan...');

        $graceHours = (int) ($this->option('grace-hours') ?? $this->expirationService->getGracePeriod()->hours());
        $threshold = now()->subHours($graceHours);

        // Active (non-suspended) servers that are past their expiry date.
        $expiredServers = Server::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $threshold)
            ->where(fn (Builder $query) => $query
                ->whereNull('status')
                ->orWhere('status', '!=', ServerState::Suspended->value))
            ->with('user')
            ->get();

        if ($expiredServers->isEmpty()) {
            $this->info('No expired servers requiring auto-suspension.');

            return self::SUCCESS;
        }

        $count = 0;
        foreach ($expiredServers as $server) {
            $this->line("Suspending Server ID {$server->id} (Name: {$server->name}) - Expired at: {$server->expires_at}");

            // Use the expiration service to check if the server should be suspended due to expiration
            if (! $this->expirationService->processExpiration($server->id)) {
                $this->info("   -> Server ID {$server->id} is not eligible for expiration-based suspension (maybe in grace period or auto-suspend disabled).");
                continue;
            }

            // Mark that we are about to perform an expiration-based suspension.
            SuspensionContext::setExpirationSuspensionInProgress(true);
            try {
                // Uses Pelican's SuspensionService: updates the `status` column to
                // `ServerState::Suspended` AND tells Wings to re-sync the server
                // state via the daemon API, which stops the server on the node.
                $this->suspensionService->handle($server, SuspendAction::Suspend);

                // Note: The actual suspension_reason will be set by the model event listener.
            } catch (Throwable $exception) {
                // Ensure flag is cleared even on failure.
                SuspensionContext::setExpirationSuspensionInProgress(false);
                Log::error("Server Expiry Plugin: Failed to suspend server ID {$server->id} ('{$server->name}'): {$exception->getMessage()}");
                $this->error("   -> Failed to suspend server ID {$server->id}: {$exception->getMessage()}");

                continue;
            } finally {
                // Clear flag in case try block succeeded without exception.
                SuspensionContext::setExpirationSuspensionInProgress(false);
            }

            // Write a system log entry for audit trails.
            Log::warning("Server Expiry Plugin: Auto-suspended server ID {$server->id} ('{$server->name}') expired at {$server->expires_at}.");

            // Notify the server owner if enabled.
            if ($this->expirationService->isNotifyOwnerOnSuspendEnabled() && $server->user) {
                try {
                    $server->user->notify(new ServerExpiredNotification($server));
                    $this->line("   -> Sent notification to owner (ID: {$server->owner_id})");
                } catch (Throwable $exception) {
                    Log::error("Failed to send server expiration notification to user #{$server->owner_id}: {$exception->getMessage()}");
                }
            }

            $count++;
        }

        $this->info("Successfully auto-suspended {$count} expired server(s).");

        return self::SUCCESS;
    }
}