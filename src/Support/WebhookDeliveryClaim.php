<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Support;

use DateTimeInterface;

/**
 * Claim/lease policy for webhook delivery workers.
 *
 * Extracted from WebhookDeliveryService so the decision rules are unit-testable
 * without a database, exactly like SuspensionGuard. The service owns the atomic
 * SQL; this class owns "may this row be claimed" and "is this claim still mine".
 *
 * The lease must outlive the longest possible single attempt, or a still-running
 * worker's row would be handed to a second worker. The HTTP timeout is capped at
 * 60 seconds by WebhookDeliveryService::timeoutSeconds(), so the lease is set to
 * a deliberate multiple of that ceiling rather than a round number chosen by feel.
 */
final class WebhookDeliveryClaim
{
    /**
     * Longest single HTTP attempt, in seconds — the enforced runtime maximum.
     */
    public const HTTP_TIMEOUT_CEILING_SECONDS = 60;

    /**
     * How long a claim lease lasts, in seconds.
     *
     * 300s is 5x the 60s HTTP ceiling. The margin covers the work around the
     * request itself (payload encoding, HMAC signing, the outcome write) and
     * clock skew between the application host and the database, which matters
     * because `now()` is evaluated in PHP while `claimed_at` is stored in the
     * database. A lease shorter than the timeout would let a slow-but-successful
     * delivery be reclaimed and sent twice.
     */
    public const LEASE_SECONDS = 300;

    /**
     * Statuses a worker is allowed to claim from.
     *
     * `pending` covers the row created just before the initial inline attempt;
     * `failed` covers a retry that is due.
     *
     * @var list<string>
     */
    public const CLAIMABLE_STATUSES = ['pending', 'failed'];

    /**
     * Generate a fresh claim token.
     */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * When a claim taken now stops being exclusive.
     */
    public static function leaseExpiry(DateTimeInterface $now): DateTimeInterface
    {
        return $now->modify('+'.self::LEASE_SECONDS.' seconds');
    }

    /**
     * A claim is stale once its lease has elapsed, measured against the same
     * clock the lease was written with.
     */
    public static function isStale(?DateTimeInterface $claimedAt, DateTimeInterface $now): bool
    {
        if ($claimedAt === null) {
            return true;
        }

        return $claimedAt->getTimestamp() <= $now->getTimestamp() - self::LEASE_SECONDS;
    }

    /**
     * Whether a live worker currently owns this row.
     */
    public static function isClaimed(?DateTimeInterface $claimedAt, DateTimeInterface $now): bool
    {
        return $claimedAt !== null && ! self::isStale($claimedAt, $now);
    }

    /**
     * Whether the row is finished and must never be claimed again.
     */
    public static function isTerminal(string $status): bool
    {
        return $status === 'success';
    }

    /**
     * Whether a worker may take this row right now.
     *
     * A freshly inserted row is `pending` with no claim; it must not be treated
     * as abandoned merely for being pending, so an unclaimed pending row is only
     * claimable once it is older than one lease (i.e. the process that created it
     * is gone). Failed rows follow the retry schedule instead.
     */
    public static function isClaimable(
        string $status,
        ?DateTimeInterface $nextAttemptAt,
        ?DateTimeInterface $claimedAt,
        int $attempt,
        int $maxAttempts,
        DateTimeInterface $now,
    ): bool {
        if (self::isTerminal($status)) {
            return false;
        }

        if (! in_array($status, self::CLAIMABLE_STATUSES, true)) {
            return false;
        }

        // `attempt` counts attempts already consumed, so a count at or above the
        // limit means the delivery has spent its entire send budget.
        if ($attempt >= $maxAttempts) {
            return false;
        }

        // Never step on a live claim.
        if (self::isClaimed($claimedAt, $now)) {
            return false;
        }

        // An unclaimed pending row is the inline attempt's own row; only recover
        // it once it is provably abandoned, never before it has been tried.
        if ($status === 'pending' && $claimedAt === null && $nextAttemptAt !== null) {
            return self::isStale($nextAttemptAt, $now);
        }

        return $nextAttemptAt === null || $nextAttemptAt->getTimestamp() <= $now->getTimestamp();
    }

    /**
     * Exponential backoff for the failure that just consumed `attempt`.
     *
     * Preserves the established schedule for the default base of 1 second:
     * 1s, 2s, 4s, 8s... The exponent uses the attempt number BEFORE it was
     * consumed on claim, matching the pre-claim implementation's delays exactly.
     */
    public static function retryDelaySeconds(int $attempt, int $baseSeconds): int
    {
        return max(1, $baseSeconds) * (2 ** (max(1, $attempt) - 1));
    }
}
