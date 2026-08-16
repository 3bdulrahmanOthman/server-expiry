<?php

namespace PelicanDev\ServerExpiry\Console\Commands;

use App\Enums\ServerState;
use App\Enums\SuspendAction;
use App\Models\Server;
use App\Services\Servers\SuspensionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use PelicanDev\ServerExpiry\Notifications\ServerExpiredNotification;
use Throwable;

class SuspendExpiredServersCommand extends Command
{
    protected $signature = 'pelican:suspend-expired-servers {--grace-hours= : Override default grace period hours}';

    protected $description = 'Checks for servers past their expires_at date and automatically suspends them via the Wings API.';

    public function __construct(private readonly SuspensionService $suspensionService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('server-expiry.auto_suspend_enabled', true)) {
            $this->warn('Auto-suspend is disabled (SERVER_EXPIRY_AUTO_SUSPEND=false). Aborting.');

            return self::SUCCESS;
        }

        $this->info('Starting expired servers scan...');

        $graceHours = (int) ($this->option('grace-hours') ?? config('server-expiry.grace_period_hours', 0));
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

            try {
                // Uses Pelican's SuspensionService: updates the `status` column to
                // `ServerState::Suspended` AND tells Wings to re-sync the server
                // state via the daemon API, which stops the server on the node.
                $this->suspensionService->handle($server, SuspendAction::Suspend);
            } catch (Throwable $exception) {
                Log::error("Server Expiry Plugin: Failed to suspend server ID {$server->id} ('{$server->name}'): {$exception->getMessage()}");
                $this->error("   -> Failed to suspend server ID {$server->id}: {$exception->getMessage()}");

                continue;
            }

            // Write a system log entry for audit trails.
            Log::warning("Server Expiry Plugin: Auto-suspended server ID {$server->id} ('{$server->name}') expired at {$server->expires_at}.");

            // Notify the server owner if enabled.
            if (config('server-expiry.notify_owner_on_suspend', true) && $server->user) {
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
