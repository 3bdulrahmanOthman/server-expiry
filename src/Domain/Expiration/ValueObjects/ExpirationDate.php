<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use SquadronStrike\ServerExpiry\Domain\Expiration\Enums\ExpirationStatus;

/**
 * Value object representing a server expiration date.
 * Encapsulates expiration date logic and provides methods
 * for determining status and calculating remaining time.
 */
final class ExpirationDate
{
    /**
     * Create a permanent expiration (no expiration date).
     */
    public static function permanent(): self
    {
        return new self(null);
    }

    /**
     * Create an expiration from a DateTimeInterface.
     *
     * @param DateTimeInterface|null $dateTime The expiration date/time, or null for permanent
     */
    public static function fromDateTime(?DateTimeInterface $dateTime): self
    {
        return new self($dateTime);
    }

    /**
     * Create an expiration from a timestamp string.
     *
     * @param string|null $dateString Date/time string parsable by DateTimeImmutable, or null for permanent
     */
    public static function fromString(?string $dateString): self
    {
        if ($dateString === null) {
            return new self(null);
        }

        try {
            // Normalize to the application's default timezone so that inbound
            // strings carrying an explicit UTC offset cannot shift the wall
            // time when the repository formats it for persistence.
            $dateTime = (new DateTimeImmutable($dateString))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
            return new self($dateTime);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException(
                sprintf('Invalid date/time string: %s', $dateString),
                0,
                $exception
            );
        }
    }

    /**
     * @param DateTimeInterface|null $dateTime The expiration date/time, or null for permanent
     */
    private function __construct(
        private readonly ?DateTimeInterface $dateTime
    ) {}

    /**
     * Check if this expiration represents a permanent server.
     */
    public function isPermanent(): bool
    {
        return $this->dateTime === null;
    }

    /**
     * Get the expiration DateTimeInterface, or null if permanent.
     */
    public function getDateTime(): ?DateTimeInterface
    {
        return $this->dateTime;
    }

    /**
     * Check if the server has expired based on current time.
     *
     * @param DateTimeInterface|null $now Optional time to check against (for testing)
     */
    public function isExpired(?DateTimeInterface $now = null): bool
    {
        if ($this->isPermanent()) {
            return false;
        }

        $now ??= new DateTimeImmutable('now');

        return $now > $this->dateTime;
    }

    /**
     * Check if the server is in the warning period.
     *
     * @param DateTimeInterface $now Current time
     * @param int $warningDays Number of days before expiration to consider as warning
     */
    public function isInWarningPeriod(DateTimeInterface $now, int $warningDays): bool
    {
        if ($this->isPermanent() || $this->isExpired($now)) {
            return false;
        }

        $warningStart = $this->dateTime->modify('-' . $warningDays . ' days');

        return $now >= $warningStart && $now < $this->dateTime;
    }

    /**
     * Check if the server is in the grace period.
     *
     * @param DateTimeInterface $now Current time
     * @param int $graceHours Number of hours after expiration for grace period
     */
    public function isInGracePeriod(DateTimeInterface $now, int $graceHours): bool
    {
        if ($this->isPermanent() || !$this->isExpired($now)) {
            return false;
        }

        $graceEnd = $this->dateTime->modify('+' . $graceHours . ' hours');

        return $now <= $graceEnd;
    }

    /**
     * Get the expiration status based on current time and configuration.
     *
     * @param DateTimeInterface $now Current time
     * @param int $warningDays Number of days before expiration for warning period
     * @param int $graceHours Number of hours after expiration for grace period
     */
    public function getStatus(DateTimeInterface $now, int $warningDays, int $graceHours): ExpirationStatus
    {
        if ($this->isPermanent()) {
            return ExpirationStatus::PERMANENT;
        }

        if ($this->isExpired($now)) {
            if ($this->isInGracePeriod($now, $graceHours)) {
                return ExpirationStatus::GRACE;
            }

            return ExpirationStatus::EXPIRED;
        }

        if ($this->isInWarningPeriod($now, $warningDays)) {
            return ExpirationStatus::WARNING;
        }

        return ExpirationStatus::ACTIVE;
    }

    /**
     * Get the remaining time as a DateInterval.
     *
     * @param DateTimeInterface|null $now Optional time to calculate from (for testing)
     * @return DateInterval|null Null if permanent, otherwise the interval until expiration
     */
    public function getRemainingTime(?DateTimeInterface $now = null): ?\DateInterval
    {
        if ($this->isPermanent()) {
            return null;
        }

        $now ??= new DateTimeImmutable('now');

        return $this->dateTime->diff($now);
    }

    /**
     * Check if this expiration date equals another.
     */
    public function equals(self $other): bool
    {
        if ($this->isPermanent() && $other->isPermanent()) {
            return true;
        }

        if ($this->isPermanent() || $other->isPermanent()) {
            return false;
        }

        return $this->dateTime->getTimestamp() === $other->dateTime->getTimestamp();
    }
}