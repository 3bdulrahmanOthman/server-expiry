<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Application\Contracts;

use SquadronStrike\ServerExpiry\Domain\Expiration\Enums\ExpirationStatus;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\ExpirationDate;

/**
 * Contract for the expiration domain service.
 * Contains the core business logic for server expiration management.
 */
interface ExpirationService
{
    /**
     * Set an expiration date for a server.
     *
     * @param string $serverId The unique identifier of the server
     * @param ExpirationDate $expirationDate The expiration date to set
     * @return void
     * @throws \SquadronStrike\ServerExpiry\Domain\Expiration\Exceptions\InvalidExpirationOperation
     */
    public function setExpiration(string $serverId, ExpirationDate $expirationDate): void;

    /**
     * Clear the expiration date for a server (make it permanent).
     *
     * @param string $serverId The unique identifier of the server
     * @return void
     * @throws \SquadronStrike\ServerExpiry\Domain\Expiration\Exceptions\InvalidExpirationOperation
     */
    public function clearExpiration(string $serverId): void;

    /**
     * Extend the expiration date for a server by a specified amount.
     *
     * @param string $serverId The unique identifier of the server
     * @param \DateInterval $interval The amount to extend the expiration by
     * @return ExpirationDate The new expiration date
     * @throws \SquadronStrike\ServerExpiry\Domain\Expiration\Exceptions\InvalidExpirationOperation
     */
    public function extendExpiration(string $serverId, \DateInterval $interval): ExpirationDate;

    /**
     * Renew a server by setting a new expiration date based on renewal logic.
     * This might involve calculating a new date based on plans, etc.
     *
     * @param string $serverId The unique identifier of the server
     * @param ExpirationDate $newExpirationDate The new expiration date to set
     * @return void
     */
    public function renew(string $serverId, ExpirationDate $newExpirationDate): void;

    /**
     * Get the current expiration status of a server.
     *
     * @param string $serverId The unique identifier of the server
     * @return ExpirationStatus The current expiration status
     */
    public function getStatus(string $serverId): ExpirationStatus;

    /**
     * Get the expiration date for a server.
     *
     * @param string $serverId The unique identifier of the server
     * @return ExpirationDate The expiration date (may be permanent)
     */
    public function getExpiration(string $serverId): ExpirationDate;

    /**
     * Check if a server is currently expired.
     *
     * @param string $serverId The unique identifier of the server
     * @return bool True if the server is expired
     */
    public function isExpired(string $serverId): bool;

    /**
     * Check if a server is currently in its grace period.
     *
     * @param string $serverId The unique identifier of the server
     * @return bool True if the server is in grace period
     */
    public function isInGracePeriod(string $serverId): bool;

    /**
     * Get the remaining time until expiration.
     *
     * @param string $serverId The unique identifier of the server
     * @return \DateInterval|null The remaining time, or null if permanent
     */
    public function getRemainingTime(string $serverId): ?\DateInterval;

    /**
     * Process expiration logic for a specific server.
     * This checks if the server should be suspended based on expiration.
     *
     * @param string $serverId The unique identifier of the server
     * @return bool True if the server was suspended due to expiration
     */
    public function processExpiration(string $serverId): bool;

    /**
     * Process expiration warnings for a specific server.
     * This sends notifications if the server is in a warning period.
     *
     * @param string $serverId The unique identifier of the server
     * @return int|null The warning threshold that was triggered, or null if none
     */
    public function processWarnings(string $serverId): ?int;
}