<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Console\Commands;

use App\Enums\ServerState;
use App\Enums\SuspendAction;
use App\Models\Server;
use App\Services\Servers\SuspensionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SquadronStrike\ServerExpiry\Application\Services\ExpirationService;
use SquadronStrike\ServerExpiry\Notifications\ServerExpiredNotification;
use SquadronStrike\ServerExpiry\Notifications\ServerExpiringWarningNotification;
use Throwable;

/**
 * Consolidated command to process server expiration lifecycle.
 * Handles warning notifications and expiration-based suspension in a single run.
 */
class ProcessServerExpirationCommand extends Command
{
    protected $signature = 'pelican:process-server-expiration
                            {--grace-hours= : Override default grace period hours}';

    protected $description = 'Processes server expiration lifecycle: sends warnings and suspends expired servers.';

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

        $this->info('Starting server expiration lifecycle processing...');

        // Process warning notifications first
        $warningCount = $this->processWarnings();

        // Process expiration/suspension
        $suspensionCount = $this->processExpirations();

        $this->info("Processed {$warningCount} warning(s) and {$suspensionCount} suspension(s).");

        return self::SUCCESS;
    }

    /**
     * Process warning notifications for servers in warning periods.
     *
     * @return int Number of warnings sent
     */
    private function processWarnings(): int
    {
        $warningDays = collect(config('server-expiry.warning_days_notice', []))
            ->map(fn ($days) => (int) $days)
            ->filter(fn ($days) => $days > 0)
            ->sort()
            ->values()
            ->all();

        if (empty($warningDays)) {
            $this->warn('No warning thresholds configured (SERVER_EXPIRY_WARNING_DAYS). Skipping warning processing.');

            return 0;
        }

        $maxWarningDay = max($warningDays);

        $this->info("Processing servers for warning notifications (within {$maxWarningDay} day(s))...");

        // Find active servers that are not yet expired but within warning window
        $servers = Server::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now()) // Not yet expired
            ->where('expires_at', '<=', now()->addDays($maxWarningDay)) // Within max warning window
            ->where(fn (Builder $query) => $query
                ->whereNull('status')
                ->orWhere('status', '!=', ServerState::Suspended->value)) // Not suspended
            ->with('user')
            ->cursor(); // Use cursor for memory efficiency

        $warningCount = 0;

        foreach ($servers as $server) {
            // Check if server is in a warning period
            $warningThreshold = $this->expirationService->processWarnings($server->id);

            if ($warningThreshold === null) {
                // Not in any warning period
                continue;
            }

            // Check idempotency: have we already sent this warning threshold for this server?
            $alreadyNotified = false;
            if (Schema::hasTable('notification_idempotency')) {
                $alreadyNotified = DB::table('notification_idempotency')
                    ->where('server_id', $server->id)
                    ->where('notification_type', 'expiry_warning')
                    ->where('identifier', (string) $warningThreshold)
                    ->exists();
            }

            if ($alreadyNotified) {
                // Already sent this warning, skip
                continue;
            }

            if (! $server->user) {
                Log::warning("Server Expiry Plugin: Server ID {$server->id} has no owner; skipping expiry warning.");

                continue;
            }

            try {
                // Calculate days remaining for the notification
                $daysRemaining = (int) ceil(now()->diffInSeconds($server->expires_at) / 86400);

                $server->user->notify(new ServerExpiringWarningNotification(
                    $server,
                    $warningThreshold,
                    $daysRemaining
                ));

                // Record the notification sent
                if (Schema::hasTable('notification_idempotency')) {
                    DB::table('notification_idempotency')->insert([
                        'server_id' => $server->id,
                        'notification_type' => 'expiry_warning',
                        'identifier' => (string) $warningThreshold,
                        'sent_at' => now(),
                    ]);
                }

                $this->line(
                    "   -> Expiry warning ({$warningThreshold}d) sent for Server ID {$server->id} (Name: {$server->name}) - {$daysRemaining} day(s) remaining"
                );
                $warningCount++;
            } catch (Throwable $exception) {
                Log::error(
                    "Server Expiry Plugin: Failed to send expiry warning for server ID {$server->id}: {$exception->getMessage()}"
                );
                $this->error(
                    "   -> Failed to send warning for Server ID {$server->id}: {$exception->getMessage()}"
                );

                // Continue with other servers
                continue;
            }
        }

        return $warningCount;
    }

    /**
     * Process expiration and suspension for expired servers.
     *
     * @return int Number of servers suspended
     */
    private function processExpirations(): int
    {
        $graceHours = (int) ($this->option('grace-hours') ?? $this->expirationService->getGracePeriod()->hours());
        $threshold = now()->subHours($graceHours);

        $this->info("Processing servers for expiration/suspension (grace period: {$graceHours} hour(s))...");

        // Find active servers that are expired (past grace period threshold)
        $servers = Server::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $threshold) // Expired (past grace period)
            ->where(fn (Builder $query) => $query
                ->whereNull('status')
                ->orWhere('status', '!=', ServerState::Suspended->value)) // Not suspended
            ->with('user')
            ->cursor(); // Use cursor for memory efficiency

        $suspensionCount = 0;

        foreach ($servers as $server) {
            // Use expiration service to check if server should be suspended due to expiration
            if (! $this->expirationService->processExpiration($server->id)) {
                $this->info(
                    "   -> Server ID {$server->id} is not eligible for expiration-based suspension (maybe in grace period or auto-suspend disabled)."
                );

                continue;
            }

            // Attempt to suspend the server
            try {
                $this->suspensionService->handle($server, SuspendAction::Suspend);

                // Send expiration notification if enabled
                if ($this->expirationService->isNotifyOwnerOnSuspendEnabled() && $server->user) {
                    try {
                        // Check idempotency: have we already sent an expiration notification for this server?
                        $alreadyNotified = false;
                        if (Schema::hasTable('notification_idempotency')) {
                            $alreadyNotified = DB::table('notification_idempotency')
                                ->where('server_id', $server->id)
                                ->where('notification_type', 'server_expired')
                                ->whereNull('identifier')
                                ->exists();
                        }

                        if (! $alreadyNotified) {
                            $server->user->notify(new ServerExpiredNotification($server));

                            // Record the notification sent
                            if (Schema::hasTable('notification_idempotency')) {
                                DB::table('notification_idempotency')->insert([
                                    'server_id' => $server->id,
                                    'notification_type' => 'server_expired',
                                    'identifier' => null,
                                    'sent_at' => now(),
                                ]);
                            }
                        }

                        $this->line("   -> Sent expiration notification to owner (ID: {$server->owner_id})");
                    } catch (Throwable $exception) {
                        Log::error(
                            "Failed to send server expiration notification to user #{$server->owner_id}: {$exception->getMessage()}"
                        );
                        // Don't fail the suspension if notification fails
                    }
                }

                $this->line(
                    "   -> Suspended Server ID {$server->id} (Name: {$server->name}) - Expired at: {$server->expires_at}"
                );
                $suspensionCount++;
            } catch (Throwable $exception) {
                Log::error(
                    "Server Expiry Plugin: Failed to suspend server ID {$server->id} ('{$server->name}'): {$exception->getMessage()}"
                );
                $this->error(
                    "   -> Failed to suspend server ID {$server->id}: {$exception->getMessage()}"
                );

                // Continue with other servers
                continue;
            }
        }

        $this->info("Successfully auto-suspended {$suspensionCount} expired server(s).");

        return $suspensionCount;
    }
}