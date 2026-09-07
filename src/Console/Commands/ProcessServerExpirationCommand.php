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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use SquadronStrike\ServerExpiry\Application\Services\ExpirationService;
use SquadronStrike\ServerExpiry\Domain\Events\ExpirationWarning;
use SquadronStrike\ServerExpiry\Domain\Events\ServerRenewed;
use SquadronStrike\ServerExpiry\Domain\Events\ServerSuspendedByExpiration;
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
            // Refresh the server to get the latest status and user
            $server->refresh();

            // Skip if server is now suspended or has no user
            if ($server->status === ServerState::Suspended->value || ! $server->user) {
                continue;
            }

            // Check if server is in a warning period
            $warningThreshold = $this->expirationService->processWarnings($server->id);

            if ($warningThreshold === null) {
                // Not in any warning period
                continue;
            }

            // Attempt to claim the warning notification for this server and threshold
            $claimed = $this->claimNotification(
                $server->id,
                'expiry_warning',
                (string) $warningThreshold
            );

            if (! $claimed) {
                // Either already sent, currently being processed, or failed but not ready to retry
                continue;
            }

            // Dispatch event that a warning should be sent (listener will handle the actual sending)
            try {
                Event::dispatch(new ExpirationWarning(
                    $server->id,
                    \SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\WarningThreshold::fromDays($warningThreshold),
                    new \DateTimeImmutable('now')
                ));
            } catch (\Throwable $e) {
                Log::error("Server Expiry Plugin: Failed to dispatch ExpirationWarning event for server ID {$server->id}: {$e->getMessage()}");
                // We still claimed the notification, so we need to mark it as failed to allow retry
                $this->markNotificationAsFailed(
                    $server->id,
                    'expiry_warning',
                    (string) $warningThreshold
                );
                // Continue to next server
                continue;
            }

            $this->line(
                "   -> Expiry warning ({$warningThreshold}d) dispatched for Server ID {$server->id} (Name: {$server->name})"
            );
            $warningCount++;
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
            // Refresh the server to get the latest status
            $server->refresh();

            // Skip if server is now suspended
            if ($server->status === ServerState::Suspended->value) {
                continue;
            }

            // Use expiration service to check if server should be suspended due to expiration
            if (! $this->expirationService->processExpiration($server->id)) {
                $this->info(
                    "   -> Server ID {$server->id} is not eligible for expiration-based suspension (maybe in grace period or auto-suspend disabled)."
                );

                continue;
            }

            // Attempt to claim the expiration notification for this server
            $claimed = $this->claimNotification(
                $server->id,
                'server_expired',
                'expiration'
            );

            if (! $claimed) {
                // Either already sent, currently being processed, or failed but not ready to retry
                continue;
            }

            // Attempt to suspend the server
            try {
                $this->suspensionService->handle($server, SuspendAction::Suspend);

                // Dispatch event that server was successfully suspended due to expiration
                try {
                    Event::dispatch(new ServerSuspendedByExpiration(
                        $server->id,
                        new \DateTimeImmutable('now')
                    ));
                } catch (\Throwable $e) {
                    Log::error("Server Expiry Plugin: Failed to dispatch ServerSuspendedByExpiration event for server ID {$server->id}: {$e->getMessage()}");
                    // We still claimed the notification, so we need to mark it as failed to allow retry
                    $this->markNotificationAsFailed(
                        $server->id,
                        'server_expired',
                        'expiration'
                    );
                    // Continue to next server
                    continue;
                }

                $this->line(
                    "   -> Suspension dispatched for Server ID {$server->id} (Name: {$server->name}) - Expired at: {$server->expires_at}"
                );
                $suspensionCount++;
            } catch (Throwable $exception) {
                Log::error(
                    "Server Expiry Plugin: Failed to suspend server ID {$server->id} ('{$server->name}'): {$exception->getMessage()}"
                );
                $this->error(
                    "   -> Failed to suspend server ID {$server->id}: {$exception->getMessage()}"
                );

                // Mark the notification as failed so it can be retried
                $this->markNotificationAsFailed(
                    $server->id,
                    'server_expired',
                    'expiration'
                );

                // Continue with other servers
                continue;
            }
        }

        $this->info("Successfully auto-suspended {$suspensionCount} expired server(s).");

        return $suspensionCount;
    }

    /**
     * Attempt to claim a notification for processing.
     *
     * @param  string  $serverId
     * @param  string  $notificationType
     * @param  string  $identifier
     * @return bool True if claimed, false otherwise
     */
    private function claimNotification(string $serverId, string $notificationType, string $identifier): bool
    {
        return DB::transaction(function () use ($serverId, $notificationType, $identifier) {
            try {
                // Try to update an existing row that is claimable
                $affected = DB::table('notification_idempotency')
                    ->where('server_id', $serverId)
                    ->where('notification_type', $notificationType)
                    ->where('identifier', $identifier)
                    ->where(function ($query) {
                        $query->where('status', 'pending')
                            ->orWhere('status', 'failed')
                            ->orWhereRaw("status = 'processing' AND last_attempt_at < NOW() - INTERVAL 5 MINUTE");
                    })
                    ->update([
                        'status' => 'processing',
                        'attempts' => DB::raw('attempts + 1'),
                        'last_attempt_at' => now(),
                    ]);

                if ($affected > 0) {
                    return true;
                }

                // Try to insert a new row (if it doesn't exist)
                DB::table('notification_idempotency')->insert([
                    'server_id' => $serverId,
                    'notification_type' => $notificationType,
                    'identifier' => $identifier,
                    'status' => 'processing',
                    'attempts' => 1,
                    'last_attempt_at' => now(),
                ]);

                return true;
            } catch (\Exception $e) {
                // If it's a duplicate key error, we treat it as not claimed (another process won the race)
                if ($e instanceof \Illuminate\Database\QueryException && $e->getCode() === '23000') {
                    return false;
                }
                // Re-throw other exceptions
                throw $e;
            }
        });
    }

    /**
     * Mark a notification as failed (to allow retry).
     *
     * @param  string  $serverId
     * @param  string  $notificationType
     * @param  string  $identifier
     * @return void
     */
    private function markNotificationAsFailed(string $serverId, string $notificationType, string $identifier): void
    {
        DB::table('notification_idempotency')
            ->where('server_id', $serverId)
            ->where('notification_type', $notificationType)
            ->where('identifier', $identifier)
            ->update([
                'status' => 'failed',
                'last_attempt_at' => now(),
            ]);
    }
}