<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Application\DTOs;

use SquadronStrike\ServerExpiry\Domain\Expiration\Enums\ExpirationStatus;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\ExpirationDate;

/**
 * Data transfer object for expiration information.
 * Used to pass expiration data between layers without exposing domain objects.
 */
final class ExpirationInfo
{
    /**
     * @param string $serverId The unique identifier of the server
     * @param ExpirationDate $expirationDate The expiration date
     * @param ExpirationStatus $status The current expiration status
     * @param \DateInterval|null $remainingTime The remaining time until expiration, or null if permanent
     * @param bool $isInGracePeriod Whether the server is in grace period
     */
    public function __construct(
        public readonly string $serverId,
        public readonly ExpirationDate $expirationDate,
        public readonly ExpirationStatus $status,
        public readonly ?\DateInterval $remainingTime,
        public readonly bool $isInGracePeriod
    ) {}

    /**
     * Create an ExpirationInfo from raw data.
     *
     * @param string $serverId The server ID
     * @param ExpirationDate $expirationDate The expiration date
     * @param ExpirationStatus $status The expiration status
     * @param \DateInterval|null $remainingTime The remaining time
     * @param bool $isInGracePeriod Whether in grace period
     * @return static
     */
    public static function fromData(
        string $serverId,
        ExpirationDate $expirationDate,
        ExpirationStatus $status,
        ?\DateInterval $remainingTime,
        bool $isInGracePeriod
    ): self {
        return new self(
            $serverId,
            $expirationDate,
            $status,
            $remainingTime,
            $isInGracePeriod
        );
    }
}