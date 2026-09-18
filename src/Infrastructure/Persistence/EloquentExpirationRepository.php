<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use SquadronStrike\ServerExpiry\Application\Contracts\ExpirationRepository;
use SquadronStrike\ServerExpiry\Domain\Expiration\Exceptions\ExpirationException;
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
     * Per-process memoization of the servers-table column checks. Each
     * Schema::hasColumn() call hits the information schema otherwise, and
     * the schema cannot change within a single process lifetime.
     *
     * @var array<string, bool>
     */
    private static array $columnCache = [];

    /**
     * Check (memoized) that the servers table has a required column.
     */
    private function hasColumn(string $column): bool
    {
        return self::$columnCache[$column] ??= Schema::hasTable('servers')
            && Schema::hasColumn('servers', $column);
    }

    /**
     * Guard a write: a missing column must never silently swallow the write
     * (that masked an incomplete plugin installation in production once).
     */
    private function assertColumnForWrite(string $column): void
    {
        if (! $this->hasColumn($column)) {
            Log::error("Server Expiry Plugin: cannot persist expiration data — servers.{$column} is missing. Re-run the plugin migrations.");

            throw new ExpirationException(
                "Server Expiry schema is incomplete: servers.{$column} does not exist. Please re-run the plugin's migrations."
            );
        }
    }

    /**
     * Log once per column when a read cannot find it, but keep the safe
     * permanent/null fallback so UI surfaces stay renderable.
     */
    private function logMissingColumnForRead(string $column): void
    {
        if (! $this->hasColumn($column) && ! isset(self::$columnCache[$column.'.logged'])) {
            self::$columnCache[$column.'.logged'] = true;
            Log::error(
                "Server Expiry Plugin: servers.{$column} is missing — falling back to permanent. Re-run the plugin migrations."
            );
        }
    }

    /**
     * Get the expiration date for a server.
     *
     * @param  int|string  $serverId  The unique identifier of the server
     * @return ExpirationDate The expiration date (may be permanent)
     */
    public function getExpiration(int|string $serverId): ExpirationDate
    {
        if (! $this->hasColumn('expires_at')) {
            $this->logMissingColumnForRead('expires_at');

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
     * @param  int|string  $serverId  The unique identifier of the server
     * @param  ExpirationDate  $expirationDate  The expiration date to set
     */
    public function setExpiration(int|string $serverId, ExpirationDate $expirationDate): void
    {
        $this->assertColumnForWrite('expires_at');

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
     * @param  int|string  $serverId  The unique identifier of the server
     */
    public function clearExpiration(int|string $serverId): void
    {
        $this->assertColumnForWrite('expires_at');

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
        // env()-backed config values arrive as strings (SERVER_EXPIRY_GRACE_HOURS=48
        // reads as "48" — only true/false/null are special-cased by env()), so the
        // infrastructure boundary normalizes to int before the strict domain
        // contract, mirroring the intval normalization of warning_days_notice.
        $hours = (int) config('server-expiry.grace_period_hours', 0);

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
        $thresholds = array_map(fn (int $day) => WarningThreshold::fromDays($day), $days);
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
     * @param  int|string  $serverId  The unique identifier of the server
     * @return string|null The suspension reason ('expiration', 'manual') or null if not suspended or reason unknown
     */
    public function getSuspensionReason(int|string $serverId): ?string
    {
        if (! $this->hasColumn('suspension_reason')) {
            $this->logMissingColumnForRead('suspension_reason');

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
     * @param  int|string  $serverId  The unique identifier of the server
     */
    public function setSuspensionDueToExpiration(int|string $serverId): void
    {
        $this->assertColumnForWrite('suspension_reason');

        // Update the suspension_reason value in the servers table
        DB::table('servers')
            ->where('id', $serverId)
            ->update(['suspension_reason' => 'expiration']);
    }

    /**
     * Clear the suspension reason for a server.
     * This is used when the server is no longer suspended due to expiration.
     *
     * @param  int|string  $serverId  The unique identifier of the server
     */
    public function clearSuspensionDueToExpiration(int|string $serverId): void
    {
        $this->assertColumnForWrite('suspension_reason');

        // Set suspension_reason to NULL
        DB::table('servers')
            ->where('id', $serverId)
            ->update(['suspension_reason' => null]);
    }
}
