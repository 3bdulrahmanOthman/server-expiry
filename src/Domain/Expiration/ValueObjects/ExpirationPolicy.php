<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects;

use InvalidArgumentException;

/**
 * Value object representing the expiration policy for a server.
 * Contains warning thresholds and grace period settings.
 */
final class ExpirationPolicy
{
    /**
     * Create an expiration policy from warning thresholds (in days) and grace period (in hours).
     *
     * @param list<int> $warningThresholdDays Array of warning thresholds in days (e.g., [7, 3, 1])
     * @param int $gracePeriodHours Grace period in hours (must be >= 0)
     * @throws InvalidArgumentException if any threshold is not positive or grace period is negative
     */
    public static function fromArray(array $warningThresholdDays, int $gracePeriodHours): self
    {
        foreach ($warningThresholdDays as $days) {
            if ($days <= 0) {
                throw new InvalidArgumentException(
                    sprintf('Warning threshold days must be positive, got %d', $days)
                );
            }
        }

        if ($gracePeriodHours < 0) {
            throw new InvalidArgumentException(
                sprintf('Grace period hours must be non-negative, got %d', $gracePeriodHours)
            );
        }

        // Sort thresholds descending so we can check the largest first if needed
        $thresholds = array_map(fn(int $days) => WarningThreshold::fromDays($days), $warningThresholdDays);
        usort($thresholds, fn(WarningThreshold $a, WarningThreshold $b) => $b->days() - $a->days());

        return new self(
            $thresholds,
            GracePeriod::fromHours($gracePeriodHours)
        );
    }

    /**
     * @param list<WarningThreshold> $warningThresholds
     * @param GracePeriod $gracePeriod
     */
    private function __construct(
        private readonly array $warningThresholds,
        private readonly GracePeriod $gracePeriod
    ) {}

    /**
     * Get the warning thresholds (sorted descending by days).
     */
    public function warningThresholds(): array
    {
        return $this->warningThresholds;
    }

    /**
     * Get the warning thresholds as an array of integers (days).
     */
    public function warningThresholdsAsDays(): array
    {
        return array_map(fn(WarningThreshold $wt) => $wt->days(), $this->warningThresholds);
    }

    /**
     * Get the grace period.
     */
    public function gracePeriod(): GracePeriod
    {
        return $this->gracePeriod;
    }

    /**
     * Get the grace period in hours.
     */
    public function gracePeriodHours(): int
    {
        return $this->gracePeriod->hours();
    }

    /**
     * Get the maximum warning threshold in days (or null if none).
     */
    public function maxWarningDays(): ?int
    {
        if (empty($this->warningThresholds)) {
            return null;
        }
        return $this->warningThresholds[0]->days(); // first element after sorting descending
    }

    /**
     * Check if this policy equals another.
     */
    public function equals(self $other): bool
    {
        if (count($this->warningThresholds) !== count($other->warningThresholds)) {
            return false;
        }

        foreach ($this->warningThresholds as $i => $threshold) {
            if (!$threshold->equals($other->warningThresholds[$i])) {
                return false;
            }
        }

        return $this->gracePeriod->equals($other->gracePeriod);
    }
}