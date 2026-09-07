<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Application\Services;

use DateTimeImmutable;
use SquadronStrike\ServerExpiry\Application\Contracts\ExpirationRepository;
use SquadronStrike\ServerExpiry\Application\Contracts\ExpirationService as ExpirationServiceInterface;
use SquadronStrike\ServerExpiry\Domain\Expiration\Enums\ExpirationStatus;
use SquadronStrike\ServerExpiry\Domain\Expiration\Exceptions\ExpirationException;
use SquadronStrike\ServerExpiry\Domain\Expiration\Exceptions\InvalidExpirationOperation;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\ExpirationDate;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\GracePeriod;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\WarningThreshold;
use SquadronStrike\ServerExpiry\Domain\Events\ExpirationCleared;
use SquadronStrike\ServerExpiry\Domain\Events\ExpirationSet;
use SquadronStrike\ServerExpiry\Domain\Events\ExpirationWarning;
use SquadronStrike\ServerExpiry\Domain\Events\ServerExpired;
use SquadronStrike\ServerExpiry\Domain\Events\ServerRenewed;
use SquadronStrike\ServerExpiry\Domain\Events\ServerSuspendedByExpiration;
use Illuminate\Support\Facades\Event;

/**
 * Application service for expiration management.
 * Orchestrates expiration lifecycle behavior using domain objects and persistence.
 */
final class ExpirationService implements ExpirationServiceInterface
{
    /**
     * @param ExpirationRepository $repository Persistence repository for expiration data
     */
    public function __construct(
        private readonly ExpirationRepository $repository
    ) {}

    /**
     * Get the expiration repository.
     *
     * @return ExpirationRepository
     */
    public function getRepository(): ExpirationRepository
    {
        return $this->repository;
    }

    /**
     * Set an expiration date for a server.
     */
    public function setExpiration(string $serverId, ExpirationDate $expirationDate): void
    {
        $currentExpiration = $this->repository->getExpiration($serverId);

        // If setting to permanent when already permanent, do nothing
        if ($expirationDate->isPermanent() && $currentExpiration->isPermanent()) {
            return;
        }

        // If setting the same expiration date, do nothing
        if (!$expirationDate->isPermanent() && !$currentExpiration->isPermanent()
            && $expirationDate->equals($currentExpiration)) {
            return;
        }

        // Persist the new expiration date
        $this->repository->setExpiration($serverId, $expirationDate);

        // Dispatch domain event
        Event::dispatch(new ExpirationSet($serverId, $expirationDate, new DateTimeImmutable('now')));
    }

    /**
     * Clear the expiration date for a server (make it permanent).
     */
    public function clearExpiration(string $serverId): void
    {
        $currentExpiration = $this->repository->getExpiration($serverId);

        // If already permanent, do nothing
        if ($currentExpiration->isPermanent()) {
            return;
        }

        // Persist the clearance
        $this->repository->clearExpiration($serverId);

        // Dispatch domain event
        Event::dispatch(new ExpirationCleared($serverId, new DateTimeImmutable('now')));
    }

    /**
     * Extend the expiration date for a server by a specified amount.
     */
    public function extendExpiration(string $serverId, \DateInterval $interval): ExpirationDate
    {
        $currentExpiration = $this->repository->getExpiration($serverId);

        if ($currentExpiration->isPermanent()) {
            throw new InvalidExpirationOperation(
                'Cannot extend expiration for a permanent server'
            );
        }

        $currentDateTime = $currentExpiration->getDateTime();
        if ($currentDateTime === null) {
            // This shouldn't happen due to isPermanent check, but for type safety
            throw new InvalidExpirationOperation(
                'Unable to retrieve current expiration date'
            );
        }

        $newDateTime = $currentDateTime->add($interval);
        $newExpiration = ExpirationDate::fromDateTime($newDateTime);

        // Persist the new expiration date
        $this->repository->setExpiration($serverId, $newExpiration);

        // Dispatch domain event
        Event::dispatch(new ExpirationSet($serverId, $newExpiration, new DateTimeImmutable('now')));
    }

    /**
     * Renew a server by setting a new expiration date based on renewal logic.
     * This might involve calculating a new date based on plans, etc.
     *
     * Important: Renewal does not affect manual suspension status.
     */
    public function renew(string $serverId, ExpirationDate $newExpirationDate): void
    {
        $currentExpiration = $this->repository->getExpiration($serverId);

        // If setting the same expiration date, do nothing
        if (!$newExpirationDate->isPermanent() && !$currentExpiration->isPermanent()
            && $newExpirationDate->equals($currentExpiration)) {
            return;
        }

        // Persist the new expiration date
        $this->repository->setExpiration($serverId, $newExpirationDate);

        // Dispatch domain event
        Event::dispatch(new ServerRenewed($serverId, $newExpirationDate, new DateTimeImmutable('now')));
    }

    /**
     * Get the current expiration status of a server.
     */
    public function getStatus(string $serverId): ExpirationStatus
    {
        $expirationDate = $this->repository->getExpiration($serverId);
        $now = new DateTimeImmutable('now');

        // Get warning thresholds and grace period from repository
        $warningThresholds = $this->repository->getWarningThresholds();
        $gracePeriod = $this->repository->getGracePeriod();

        // Convert warning thresholds to array of days (integers)
        $warningDaysArray = array_map(
            fn(WarningThreshold $wt) => $wt->days(),
            $warningThresholds
        );

        // Use the maximum warning threshold for status calculation
        // The ExpirationDate::getStatus method expects a single warningDays value
        // We'll use the largest threshold to determine if we're in any warning period
        $maxWarningDays = max($warningDaysArray);

        return $expirationDate->getStatus(
            $now,
            $maxWarningDays,
            $gracePeriod->hours()
        );
    }

    /**
     * Get the expiration date for a server.
     */
    public function getExpiration(string $serverId): ExpirationDate
    {
        return $this->repository->getExpiration($serverId);
    }

    /**
     * Check if a server is currently expired.
     */
    public function isExpired(string $serverId): bool
    {
        $expirationDate = $this->repository->getExpiration($serverId);
        $now = new DateTimeImmutable('now');

        return $expirationDate->isExpired($now);
    }

    /**
     * Check if a server is currently in its grace period.
     */
    public function isInGracePeriod(string $serverId): bool
    {
        $expirationDate = $this->repository->getExpiration($serverId);
        $now = new DateTimeImmutable('now');

        // Get grace period from repository
        $gracePeriod = $this->repository->getGracePeriod();

        return $expirationDate->isInGracePeriod($now, $gracePeriod->hours());
    }

    /**
     * Get the remaining time until expiration.
     */
    public function getRemainingTime(string $serverId): ?\DateInterval
    {
        $expirationDate = $this->repository->getExpiration($serverId);
        $now = new DateTimeImmutable('now');

        return $expirationDate->getRemainingTime($now);
    }

    /**
     * Process expiration logic for a specific server.
     * This checks if the server should be suspended based on expiration.
     *
     * Returns true if the server was suspended due to expiration.
     * Note: Actual suspension would be handled by infrastructure layer
     * based on this service's return value and suspension configuration.
     */
    public function processExpiration(string $serverId): bool
    {
        // Check if auto-suspansion is enabled
        if (!$this->repository->isAutoSuspendEnabled()) {
            return false;
        }

        $expirationDate = $this->repository->getExpiration($serverId);
        $now = new DateTimeImmutable('now');

        // Check if server is expired
        if (!$expirationDate->isExpired($now)) {
            return false;
        }

        // Check if server is in grace period
        if ($this->isInGracePeriod($serverId)) {
            return false; // Still in grace period, not suspended yet
        }

        // Server is expired and grace period has elapsed - should be suspended
        // In a full implementation, we would:
        // 1. Check if already suspended by expiration (to avoid duplicate events)
        // 2. Suspend the server via infrastructure
        // 3. Dispatch ServerSuspendedByExpiration event

        // For now, we return true to indicate that expiration-based suspension should occur
        // The actual suspension logic would be in a command or scheduler that uses this service

        // Dispatch domain event (would be done by caller)
        // Event::dispatch(new ServerSuspendedByExpiration($serverId, $now));

        return true;
    }

    /**
     * Process expiration warnings for a specific server.
     * This sends notifications if the server is in a warning period.
     *
     * Returns the warning threshold that was triggered, or null if none.
     */
    public function processWarnings(string $serverId): ?int
    {
        $expirationDate = $this->repository->getExpiration($serverId);
        $now = new DateTimeImmutable('now');

        // Get warning thresholds from repository
        $warningThresholds = $this->repository->getWarningThresholds();

        // Convert to array of WarningThreshold objects sorted by days descending
        // so we check largest threshold first
        usort($warningThresholds, fn(WarningThreshold $a, WarningThreshold $b) =>
            $b->days() - $a->days()
        );

        // Check each warning threshold
        foreach ($warningThresholds as $threshold) {
            $days = $threshold->days();
            if ($expirationDate->isInWarningPeriod($now, $days)) {
                return $days;
            }
        }

        return null;
    }

    /**
     * Check if auto-suspension is enabled.
     *
     * @return bool True if auto-suspension is enabled
     */
    public function isAutoSuspendEnabled(): bool
    {
        return $this->repository->isAutoSuspendEnabled();
    }

    /**
     * Check if owner notifications are enabled on suspension.
     *
     * @return bool True if owner notifications are enabled
     */
    public function isNotifyOwnerOnSuspendEnabled(): bool
    {
        return $this->repository->isNotifyOwnerOnSuspendEnabled();
    }
}