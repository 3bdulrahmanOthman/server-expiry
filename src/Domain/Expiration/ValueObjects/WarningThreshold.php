<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects;

/**
 * Value object representing a warning threshold in days.
 * Ensures the threshold is a positive integer.
 */
final class WarningThreshold
{
    /**
     * Create a warning threshold from days.
     *
     * @param int $days Number of days, must be > 0
     * @throws InvalidArgumentException if days is not positive
     */
    public static function fromDays(int $days): self
    {
        if ($days <= 0) {
            throw new InvalidArgumentException(
                sprintf('Warning threshold days must be positive, got %d', $days)
            );
        }

        return new self($days);
    }

    /**
     * @param int $days Number of days
     */
    private function __construct(
        private readonly int $days
    ) {}

    /**
     * Get the warning threshold in days.
     */
    public function days(): int
    {
        return $this->days;
    }

    /**
     * Check if this warning threshold equals another.
     */
    public function equals(self $other): bool
    {
        return $this->days === $other->days;
    }
}