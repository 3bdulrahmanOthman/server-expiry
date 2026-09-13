<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SquadronStrike\ServerExpiry\Application\Contracts\ExpirationRepository;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\ExpirationDate;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\GracePeriod;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\WarningThreshold;

/**
 * Eloquent implementation of the expiration repository.
 * Handles persistence of expiration data to the servers table.
 */
class EloquentExpirationRepository implements ExpirationRepository
{
    /**
     * Get the expiration date for a server.
     *
     * @param string $serverId The unique identifier of the server
     * @return ExpirationDate The expiration date (may be permanent)
     */
    public function getExpiration(string $serverId): ExpirationDate
    {
        // Check if the servers table exists and has the expires_at column
        if (!Schema::hasTable('servers') || !Schema::hasColumn('servers', 'expires_at')) {
            // If table/column doesn't exist yet, treat as permanent (backward compatibility)
            return ExpirationDate::permanent();
        }

        // Get the expires_at value from the servers table
        $expiresAt = DB::table('servers')
            ->where('id', $serverId)
            ->value('expires_at');

        // If expires_at is null or doesn't exist, treat as permanent
        if ($expiresAt === null) {
            return ExpirationDate::permanent();
        }

        // Otherwise, return the expiration date
        return ExpirationDate::fromString($expiresAt);
    }

    /**
     * Set the expiration date for a server.
     *
     * @param string $serverId The unique identifier of the server
     * @param ExpirationDate $expirationDate The expiration date to set
     * @return void
     */
    public function setExpiration(string $serverId, ExpirationDate $expirationDate): void
    {
        // Check if the servers table exists and has the expires_at column
        if (!Schema::hasTable('servers') || !Schema::hasColumn('servers', 'expires_at')) {
            // If table/column doesn't exist yet, we can't persist the data
            // In a real implementation, we might want to log this or throw an exception
            return;
        }

        // Update the expires_at value in the servers table
        $dateTime = $expirationDate->getDateTime();
        $formattedDate = $dateTime ? $dateTime->format('Y-m-d H:i:s') : null;

        DB::table('servers')
            ->where('id', $serverId)
            ->update(['expires_at' => $formattedDate]);
    }

    /**
     * Clear the expiration date for a server (make it permanent).
     *
     * @param string $serverId The unique identifier of the server
     * @return void
     */
    public function clearExpiration(string $serverId): void
    {
        // Check if the servers table exists and has the expires_at column
        if (!Schema::hasTable('servers') || !Schema::hasColumn('servers', 'expires_at')) {
            // If table/column doesn't exist yet, we can't persist the data
            return;
        }

        // Set expires_at to NULL to make it permanent
        DB::table('servers')
            ->where('id', $serverId)
            ->update(['expires_at' => null]);
    }

    /**
     * Get the grace period configuration.
     *
     * @return GracePeriod The configured grace period
     */
    public function getGracePeriod(): GracePeriod
    {
        $hours = config('server-expiry.grace_period_hours', 0);
        return GracePeriod::fromHours($hours);
    }

    /**
     * Get the warning thresholds configuration.
     *
     * @return WarningThreshold[] Array of warning thresholds, sorted ascending
     */
    public function getWarningThresholds(): array
    {
        $days = config('server-expiry.warning_days_notice', [7, 3, 1]);
        // Drop zero/negative entries so a misconfigured value (e.g. empty env
        // string collapsing to [0]) cannot throw in WarningThreshold::fromDays
        $days = array_values(array_filter($days, fn (int $day) => $day > 0));
        $thresholds = array_map(fn(int $day) => WarningThreshold::fromDays($day), $days);
        sort($thresholds); // Sort ascending for consistent processing
        return $thresholds;
    }

    /**
     * Check if auto-suspension is enabled.
     *
     * @return bool True if auto-suspension is enabled
     */
    public function isAutoSuspendEnabled(): bool
    {
        return config('server-expiry.auto_suspend_enabled', true);
    }

    /**
     * Check if owner notifications are enabled on suspension.
     *
     * @return bool True if owner notifications are enabled
     */
    public function isNotifyOwnerOnSuspendEnabled(): bool
    {
        return config('server-expiry.notify_owner_on_suspend', true);
    }

    /**
     * Get the suspension reason for a server.
     *
     * @param string $serverId The unique identifier of the server
     * @return string|null The suspension reason ('expiration', 'manual') or null if not suspended or reason unknown
     */
    public function getSuspensionReason(string $serverId): ?string
    {
        // Check if the servers table exists and has the suspension_reason column
        if (!Schema::hasTable('servers') || !Schema::hasColumn('servers', 'suspension_reason')) {
            // If table/column doesn't exist yet, we cannot determine the reason
            return null;
        }

        // Get the suspension_reason value from the servers table
        $reason = DB::table('servers')
            ->where('id', $serverId)
            ->value('suspension_reason');

        return $reason;
    }

    /**
     * Set the suspension reason to expiration for a server.
     * This indicates that the server is suspended due to expiration.
     *
     * @param string $serverId The unique identifier of the server
     * @return void
     */
    public function setSuspensionDueToExpiration(string $serverId): void
    {
        // Check if the servers table exists and has the suspension_reason column
        if (!Schema::hasTable('servers') || !Schema::hasColumn('servers', 'suspension_reason')) {
            // If table/column doesn't exist yet, we cannot persist the data
            return;
        }

        // Update the suspension_reason value in the servers table
        DB::table('servers')
            ->where('id', $serverId)
            ->update(['suspension_reason' => 'expiration']);
    }

    /**
     * Clear the suspension reason for a server.
     * This is used when the server is no longer suspended due to expiration.
     *
     * @param string $serverId The unique identifier of the server
     * @return void
     */
    public function clearSuspensionDueToExpiration(string $serverId): void
    {
        // Check if the servers table exists and has the suspension_reason column
        if (!Schema::hasTable('servers') || !Schema::hasColumn('servers', 'suspension_reason')) {
            // If table/column doesn't exist yet, we cannot persist the data
            return;
        }

        // Set suspension_reason to NULL
        DB::table('servers')
            ->where('id', $serverId)
            ->update(['suspension_reason' => null]);
    }
}