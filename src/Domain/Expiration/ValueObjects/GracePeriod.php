<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects;

/**
 * Value object representing a grace period in hours.
 * Ensures the grace period is a non-negative integer.
 */
final class GracePeriod
{
    /**
     * Create a grace period from hours.
     *
     * @param int $hours Number of hours, must be >= 0
     * @throws InvalidArgumentException if hours is negative
     */
    public static function fromHours(int $hours): self
    {
        if ($hours < 0) {
            throw new InvalidArgumentException(
                sprintf('Grace period hours must be non-negative, got %d', $hours)
            );
        }

        return new self($hours);
    }

    /**
     * Create a zero grace period (no grace period).
     */
    public static function none(): self
    {
        return new self(0);
    }

    /**
     * @param int $hours Number of hours
     */
    private function __construct(
        private readonly int $hours
    ) {}

    /**
     * Get the grace period in hours.
     */
    public function hours(): int
    {
        return $this->hours;
    }

    /**
     * Check if grace period is enabled (greater than 0).
     */
    public function isEnabled(): bool
    {
        return $this->hours > 0;
    }

    /**
     * Check if this grace period equals another.
     */
    public function equals(self $other): bool
    {
        return $this->hours === $other->hours;
    }
}