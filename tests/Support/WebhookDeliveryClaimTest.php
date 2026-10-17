<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Support;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SquadronStrike\ServerExpiry\Support\WebhookDeliveryClaim;

/**
 * Claim/lease policy for the crash-safe webhook delivery lifecycle.
 *
 * These tests cover the framework-free decision rules directly. The service's
 * guarded UPDATE is exercised separately by
 * WebhookDeliveryClaimSqlTest, which proves the same predicate is atomic against
 * a real SQLite database.
 */
final class WebhookDeliveryClaimTest extends TestCase
{
    public function test_token_is_unique_per_claim(): void
    {
        $tokens = [];

        for ($i = 0; $i < 50; $i++) {
            $tokens[] = WebhookDeliveryClaim::newToken();
        }

        self::assertCount(50, array_unique($tokens));
        self::assertSame(32, strlen($tokens[0]), 'Token is 16 random bytes hex-encoded');
    }

    public function test_lease_outlives_the_http_timeout_ceiling(): void
    {
        self::assertGreaterThan(
            WebhookDeliveryClaim::HTTP_TIMEOUT_CEILING_SECONDS,
            WebhookDeliveryClaim::LEASE_SECONDS,
            'A lease shorter than the timeout would let a slow but successful delivery be sent twice'
        );

        // Deliberate margin, not a tight bound: the lease must also absorb the
        // work around the request and clock skew between app host and database.
        self::assertGreaterThanOrEqual(3 * WebhookDeliveryClaim::HTTP_TIMEOUT_CEILING_SECONDS, WebhookDeliveryClaim::LEASE_SECONDS);
    }

    public function test_lease_expiry_is_lease_seconds_after_now(): void
    {
        $now = new DateTimeImmutable('2026-01-01 12:00:00');

        self::assertSame(
            '2026-01-01 12:05:00',
            WebhookDeliveryClaim::leaseExpiry($now)->format('Y-m-d H:i:s')
        );
    }

    public function test_missing_claim_is_stale(): void
    {
        self::assertTrue(WebhookDeliveryClaim::isStale(null, new DateTimeImmutable('2026-01-01 12:00:00')));
        self::assertFalse(WebhookDeliveryClaim::isClaimed(null, new DateTimeImmutable('2026-01-01 12:00:00')));
    }

    public function test_claim_is_live_until_exactly_the_lease_boundary(): void
    {
        $now = new DateTimeImmutable('2026-01-01 12:00:00');
        $claimed = new DateTimeImmutable('2026-01-01 11:55:00'); // exactly 300s ago

        self::assertTrue(
            WebhookDeliveryClaim::isStale($claimed, $now),
            'At exactly one lease the claim stops being exclusive'
        );
        self::assertFalse(WebhookDeliveryClaim::isClaimed($claimed, $now));

        $justInside = new DateTimeImmutable('2026-01-01 11:55:01');
        self::assertFalse(WebhookDeliveryClaim::isStale($justInside, $now));
        self::assertTrue(WebhookDeliveryClaim::isClaimed($justInside, $now));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function terminalStatuses(): array
    {
        return ['success' => ['success']];
    }

    #[DataProvider('terminalStatuses')]
    public function test_success_is_never_claimable(string $status): void
    {
        $now = new DateTimeImmutable('2026-01-01 12:00:00');

        self::assertTrue(WebhookDeliveryClaim::isTerminal($status));
        self::assertFalse(WebhookDeliveryClaim::isClaimable($status, null, null, 1, 3, $now));
    }

    public function test_due_failed_row_is_claimable_within_budget(): void
    {
        $now = new DateTimeImmutable('2026-01-01 12:00:00');

        self::assertTrue(WebhookDeliveryClaim::isClaimable(
            'failed',
            new DateTimeImmutable('2026-01-01 11:59:00'), // due
            null,
            1,
            3,
            $now
        ));
    }

    public function test_failed_row_is_not_claimable_before_it_is_due(): void
    {
        $now = new DateTimeImmutable('2026-01-01 12:00:00');

        self::assertFalse(WebhookDeliveryClaim::isClaimable(
            'failed',
            new DateTimeImmutable('2026-01-01 12:00:30'), // still backing off
            null,
            1,
            3,
            $now
        ));
    }

    public function test_row_with_live_claim_is_not_claimable(): void
    {
        $now = new DateTimeImmutable('2026-01-01 12:00:00');

        self::assertFalse(
            WebhookDeliveryClaim::isClaimable('failed', $now, new DateTimeImmutable('2026-01-01 11:59:00'), 1, 3, $now),
            'A live lease must protect the row from a second worker'
        );
    }

    public function test_expired_lease_is_reclaimable(): void
    {
        $now = new DateTimeImmutable('2026-01-01 12:00:00');

        self::assertTrue(
            WebhookDeliveryClaim::isClaimable('pending', $now, new DateTimeImmutable('2026-01-01 11:50:00'), 1, 3, $now),
            'A worker that crashed mid-request leaves a reclaimable lease'
        );
    }

    public function test_fresh_unclaimed_pending_row_is_not_reclaimed_prematurely(): void
    {
        $now = new DateTimeImmutable('2026-01-01 12:00:00');

        self::assertFalse(
            WebhookDeliveryClaim::isClaimable('pending', $now, null, 1, 3, $now),
            'A row created moments ago is still owned by the inline attempt'
        );
    }

    public function test_abandoned_unclaimed_pending_row_is_recoverable(): void
    {
        $now = new DateTimeImmutable('2026-01-01 12:00:00');

        self::assertTrue(
            WebhookDeliveryClaim::isClaimable(
                'pending',
                new DateTimeImmutable('2026-01-01 11:50:00'), // older than one lease
                null,
                1,
                3,
                $now
            ),
            'Defect 2: a crash between insert and claim must not strand the row forever'
        );
    }

    /**
     * `attempt` counts consumed attempts, so a row is claimable exactly while its
     * consumed count is below the configured maximum — which makes max_attempts
     * the exact number of sends, the initial one included.
     *
     * @return array<string, array{0: int, 1: int, 2: bool}>
     */
    public static function consumedAttemptBudgets(): array
    {
        return [
            // attempt, maxAttempts, claimable
            'max 1, none consumed' => [0, 1, true],
            'max 1, one consumed' => [1, 1, false],
            'max 2, none consumed' => [0, 2, true],
            'max 2, one consumed' => [1, 2, true],
            'max 2, two consumed' => [2, 2, false],
            'max 3, none consumed' => [0, 3, true],
            'max 3, one consumed' => [1, 3, true],
            'max 3, two consumed' => [2, 3, true],
            'max 3, three consumed' => [3, 3, false],
            'max 3, over-consumed' => [4, 3, false],
        ];
    }

    #[DataProvider('consumedAttemptBudgets')]
    public function test_attempt_budget_admits_exactly_max_attempts_sends(
        int $attempt,
        int $maxAttempts,
        bool $claimable,
    ): void {
        $now = new DateTimeImmutable('2026-01-01 12:00:00');
        $due = new DateTimeImmutable('2026-01-01 11:00:00');

        self::assertSame(
            $claimable,
            WebhookDeliveryClaim::isClaimable('failed', $due, null, $attempt, $maxAttempts, $now),
            "attempt={$attempt}, max_attempts={$maxAttempts}"
        );
    }

    public function test_exhausted_attempt_budget_is_never_claimable(): void
    {
        $now = new DateTimeImmutable('2026-01-01 12:00:00');
        $due = new DateTimeImmutable('2026-01-01 11:00:00');

        self::assertFalse(WebhookDeliveryClaim::isClaimable('failed', $due, null, 3, 3, $now));
        self::assertFalse(WebhookDeliveryClaim::isClaimable('pending', $due, null, 4, 3, $now));
        self::assertTrue(
            WebhookDeliveryClaim::isClaimable('failed', $due, null, 2, 3, $now),
            'The last permitted attempt is still claimable'
        );
    }

    /**
     * The schedule must not shift when claiming starts consuming the attempt.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public static function backoffSchedule(): array
    {
        return [
            'first failure' => [1, 1],
            'second failure' => [2, 2],
            'third failure' => [3, 4],
            'fourth failure' => [4, 8],
        ];
    }

    #[DataProvider('backoffSchedule')]
    public function test_backoff_schedule_is_preserved(int $attempt, int $expectedSeconds): void
    {
        self::assertSame($expectedSeconds, WebhookDeliveryClaim::retryDelaySeconds($attempt, 1));
    }

    public function test_backoff_scales_with_base_and_never_collapses(): void
    {
        self::assertSame([5, 10, 20], [
            WebhookDeliveryClaim::retryDelaySeconds(1, 5),
            WebhookDeliveryClaim::retryDelaySeconds(2, 5),
            WebhookDeliveryClaim::retryDelaySeconds(3, 5),
        ]);

        self::assertSame(1, WebhookDeliveryClaim::retryDelaySeconds(1, 0), 'A zero base must not collapse the backoff');
    }
}
