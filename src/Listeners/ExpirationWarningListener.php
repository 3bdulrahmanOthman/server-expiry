<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Listeners;

use App\Models\Server;
use Illuminate\Support\Facades\Notification;
use SquadronStrike\ServerExpiry\Domain\Events\ExpirationWarning;
use SquadronStrike\ServerExpiry\Notifications\ServerExpiringWarningNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Handle the ExpirationWarning event.
 * Sends the actual warning notification and records idempotency.
 */
class ExpirationWarningListener
{
    /**
     * Handle the event.
     */
    public function handle(ExpirationWarning $event): void
    {
        $serverId = $event->serverId;
        $notificationType = 'expiry_warning';
        $identifier = (string) $event->threshold->days();

        $server = Server::find($serverId);
        if (! $server || ! $server->user) {
            // Mark as failed so it's not retried (server doesn't exist or has no user)
            try {
                DB::table('notification_idempotency')
                    ->where('server_id', $serverId)
                    ->where('notification_type', $notificationType)
                    ->where('identifier', $identifier)
                    ->update([
                        'status' => 'failed',
                        'last_attempt_at' => now(),
                    ]);
            } catch (\Exception $e) {
                // Log but don't throw - we already have the server issue
                Log::warning("Server Expiry Plugin: Could not update idempotency for non-existent/serverless server ID {$serverId}: {$e->getMessage()}");
            }
            return;
        }

        try {
            // Send the notification synchronously
            Notification::sendNow(
                $server->user,
                new ServerExpiringWarningNotification(
                    $server,
                    $event->threshold->days(),
                    (int) ceil(now()->diffInSeconds($server->expires_at) / 86400)
                )
            );

            // Update the idempotency record to sent
            DB::table('notification_idempotency')
                ->where('server_id', $serverId)
                ->where('notification_type', $notificationType)
                ->where('identifier', $identifier)
                ->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);
        } catch (\Throwable $exception) {
            // Log the error
            Log::error(
                "Server Expiry Plugin: Failed to send expiry warning for server ID {$serverId}: {$exception->getMessage()}"
            );

            // Update the idempotency record to failed
            DB::table('notification_idempotency')
                ->where('server_id', $serverId)
                ->where('notification_type', $notificationType)
                ->where('identifier', $identifier)
                ->update([
                    'status' => 'failed',
                    'last_attempt_at' => now(),
                ]);

            // Re-throw to let the caller handle the failure (if any)
            throw $exception;
        }
    }
}