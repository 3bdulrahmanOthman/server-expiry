<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Application\Contracts;

use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\ExpirationDate;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\GracePeriod;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\WarningThreshold;

/**
 * Contract for persisting and retrieving server expiration data.
 * Implementations handle the storage details while keeping the domain pure.
 */
interface ExpirationRepository
{
    /**
     * Get the expiration date for a server.
     *
     * @param string $serverId The unique identifier of the server
     * @return ExpirationDate The expiration date (may be permanent)
     */
    public function getExpiration(string $serverId): ExpirationDate;

    /**
     * Set the expiration date for a server.
     *
     * @param string $serverId The unique identifier of the server
     * @param ExpirationDate $expirationDate The expiration date to set
     * @return void
     */
    public function setExpiration(string $serverId, ExpirationDate $expirationDate): void;

    /**
     * Clear the expiration date for a server (make it permanent).
     *
     * @param string $serverId The unique identifier of the server
     * @return void
     */
    public function clearExpiration(string $serverId): void;

    /**
     * Get the grace period configuration.
     *
     * @return GracePeriod The configured grace period
     */
    public function getGracePeriod(): GracePeriod;

    /**
     * Get the warning thresholds configuration.
     *
     * @return WarningThreshold[] Array of warning thresholds, sorted ascending
     */
    public function getWarningThresholds(): array;

    /**
     * Check if auto-suspension is enabled.
     *
     * @return bool True if auto-suspension is enabled
     */
    public function isAutoSuspendEnabled(): bool;

    /**
     * Check if owner notifications are enabled on suspension.
     *
     * @return bool True if owner notifications are enabled
     */
    public function isNotifyOwnerOnSuspendEnabled(): bool;
}