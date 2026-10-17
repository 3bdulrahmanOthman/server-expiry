<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Support;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SquadronStrike\ServerExpiry\Support\WebhookDeliveryClaim;

/**
 * Proves the claim predicate is an atomic compare-and-set against a REAL
 * database engine, using the same SQLite driver the panel configures for tests.
 *
 * Scope and honest limitations:
 *  - This executes the predicate's SQL directly rather than through Eloquent, so
 *    it proves the SQL semantics (guarded UPDATE affects 1 row for the winner and
 *    0 for the loser) rather than the framework binding.
 *  - The predicate below mirrors WebhookDeliveryService::claim(). If the service
 *    query changes, this test must change with it; the expectation table asserts
 *    the same cases as WebhookDeliveryClaimTest so a divergence is visible.
 *  - MySQL is NOT covered here and is not installed on this machine; the guarded
 *    `UPDATE ... WHERE` form is standard on both engines, but the MySQL run
 *    happens in CI, not locally.
 */
final class WebhookDeliveryClaimSqlTest extends TestCase
{
    private const CLAIM_SQL = <<<'SQL'
        UPDATE webhook_deliveries
           SET claim_token = :token,
               claimed_at = :now,
               attempt = attempt + 1
         WHERE id = :id
           AND status IN ('pending','failed')
           AND attempt < max_attempts
           AND (
                 (status = 'failed'
                   AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
                   AND (claimed_at IS NULL OR claimed_at <= :stale))
              OR (status = 'pending' AND claimed_at IS NOT NULL AND claimed_at <= :stale)
              OR (status = 'pending' AND claimed_at IS NULL AND next_attempt_at <= :stale)
           )
        SQL;

    /**
     * Mirrors WebhookDeliveryService::finalizeAbandonedExhaustedClaims(): the
     * candidate SELECT followed by the per-row ownership CAS.
     */
    private const RECOVER_CANDIDATES_SQL = <<<'SQL'
        SELECT id, claim_token, endpoint_id FROM webhook_deliveries
         WHERE status IN ('pending','failed')
           AND attempt >= max_attempts
           AND claim_token IS NOT NULL
           AND claimed_at IS NOT NULL
           AND claimed_at <= :stale
           AND processed_at IS NULL
        SQL;

    private const RECOVER_SQL = <<<'SQL'
        UPDATE webhook_deliveries
           SET status = 'failed',
               error_message = :message,
               processed_at = :now,
               claim_token = NULL,
               claimed_at = NULL
         WHERE id = :id
           AND claim_token = :token
           AND processed_at IS NULL
        SQL;

    private const ABANDONED_MESSAGE = 'Delivery abandoned: the worker holding the final attempt stopped responding';

    private PDO $pdo;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            "CREATE TABLE webhook_deliveries (
                id INTEGER PRIMARY KEY,
                status VARCHAR CHECK (status IN ('pending','success','failed')),
                attempt INTEGER NOT NULL DEFAULT 1,
                max_attempts INTEGER NOT NULL DEFAULT 3,
                next_attempt_at TEXT NULL,
                claimed_at TEXT NULL,
                claim_token VARCHAR(64) NULL,
                processed_at TEXT NULL,
                error_message TEXT NULL,
                endpoint_id INTEGER NULL
            )"
        );

        // The endpoint carries the failure counters the terminal failure updates.
        $this->pdo->exec(
            'CREATE TABLE webhook_endpoints (
                id INTEGER PRIMARY KEY,
                failure_count INTEGER NOT NULL DEFAULT 0,
                last_failed_at TEXT NULL
            )'
        );
        $this->pdo->exec('INSERT INTO webhook_endpoints (id, failure_count) VALUES (1, 0)');

        $this->now = new DateTimeImmutable('2026-01-01 12:00:00');
    }

    private function insert(
        int $id,
        string $status,
        int $attempt = 1,
        int $maxAttempts = 3,
        ?string $nextAttemptAt = null,
        ?string $claimedAt = null,
        ?string $claimToken = null,
        ?string $processedAt = null,
        ?int $endpointId = null,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO webhook_deliveries
                (id, status, attempt, max_attempts, next_attempt_at, claimed_at, claim_token, processed_at, endpoint_id)
             VALUES (:id, :status, :attempt, :max, :next, :claimed, :token, :processed, :endpoint)'
        );
        $statement->execute([
            ':id' => $id,
            ':status' => $status,
            ':attempt' => $attempt,
            ':max' => $maxAttempts,
            ':next' => $nextAttemptAt,
            ':claimed' => $claimedAt,
            ':token' => $claimToken,
            ':processed' => $processedAt,
            ':endpoint' => $endpointId,
        ]);
    }

    private function claim(int $id, string $token): int
    {
        $statement = $this->pdo->prepare(self::CLAIM_SQL);
        $statement->execute([
            ':token' => $token,
            ':now' => $this->now->format('Y-m-d H:i:s'),
            ':stale' => $this->now->modify('-'.WebhookDeliveryClaim::LEASE_SECONDS.' seconds')->format('Y-m-d H:i:s'),
            ':id' => $id,
        ]);

        return $statement->rowCount();
    }

    /**
     * Release a claim the way a finished worker does: the lease is left in the
     * past and the retry is already due.
     */
    private function release(int $id): void
    {
        $this->pdo->exec(
            "UPDATE webhook_deliveries
                SET claimed_at = '2026-01-01 11:00:00', claim_token = NULL, next_attempt_at = '2026-01-01 11:00:00'
              WHERE id = {$id}"
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        return $this->pdo->query("SELECT * FROM webhook_deliveries WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>
     */
    private function endpoint(int $id = 1): array
    {
        return $this->pdo->query("SELECT * FROM webhook_endpoints WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * The rows the recovery pass selects before it writes anything.
     *
     * @return list<array<string, mixed>>
     */
    private function recoverCandidates(): array
    {
        $statement = $this->pdo->prepare(self::RECOVER_CANDIDATES_SQL);
        $statement->execute([
            ':stale' => $this->now->modify('-'.WebhookDeliveryClaim::LEASE_SECONDS.' seconds')->format('Y-m-d H:i:s'),
        ]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Finalize one preselected candidate through the ownership CAS, counting the
     * endpoint failure only when this caller won it.
     *
     * Split out from recover() so a test can interleave two ticks that preselected
     * the same row, which is what actually proves the finalization is exactly-once.
     *
     * @param  array<string, mixed>  $candidate
     */
    private function finalizeCandidate(array $candidate): bool
    {
        $finalize = $this->pdo->prepare(self::RECOVER_SQL);
        $finalize->execute([
            ':message' => self::ABANDONED_MESSAGE,
            ':now' => $this->now->format('Y-m-d H:i:s'),
            ':id' => $candidate['id'],
            ':token' => $candidate['claim_token'],
        ]);

        if ($finalize->rowCount() !== 1) {
            return false;
        }

        if ($candidate['endpoint_id'] !== null) {
            $this->pdo->exec(
                "UPDATE webhook_endpoints
                    SET failure_count = failure_count + 1, last_failed_at = '{$this->now->format('Y-m-d H:i:s')}'
                  WHERE id = {$candidate['endpoint_id']}"
            );
        }

        return true;
    }

    /**
     * One whole recovery pass: select the stranded rows, then finalize each.
     */
    private function recover(): int
    {
        $finalizedCount = 0;

        foreach ($this->recoverCandidates() as $candidate) {
            if ($this->finalizeCandidate($candidate)) {
                $finalizedCount++;
            }
        }

        return $finalizedCount;
    }

    /**
     * How a worker records its outcome, in the shape the service uses.
     */
    private function finalizeAsWorker(int $id, string $token, string $outcome): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE webhook_deliveries SET status = :outcome WHERE id = :id AND claim_token = :token'
        );
        $statement->execute([':outcome' => $outcome, ':id' => $id, ':token' => $token]);

        return $statement->rowCount();
    }

    public function test_only_one_of_two_racing_workers_can_claim_a_due_row(): void
    {
        $this->insert(1, 'failed', 1, 3, '2026-01-01 11:59:00');

        // Both workers run the same statement against the same row.
        $first = $this->claim(1, 'worker-a');
        $second = $this->claim(1, 'worker-b');

        self::assertSame(1, $first, 'The first worker takes the claim');
        self::assertSame(0, $second, 'The loser must affect zero rows and not send');
        self::assertSame('worker-a', $this->row(1)['claim_token']);
    }

    public function test_claiming_consumes_one_attempt(): void
    {
        $this->insert(1, 'failed', 0, 3, '2026-01-01 11:59:00');

        $this->claim(1, 'worker-a');

        self::assertSame(1, (int) $this->row(1)['attempt'], 'A new row starts at 0; the first claim consumes 1');
    }

    /**
     * The configured maximum must be the exact number of sends, initial included.
     *
     * @return array<string, array{0: int}>
     */
    public static function attemptBudgets(): array
    {
        return ['max 1' => [1], 'max 2' => [2], 'max 3' => [3], 'max 10' => [10]];
    }

    #[DataProvider('attemptBudgets')]
    public function test_max_attempts_is_the_exact_number_of_permitted_sends(int $maxAttempts): void
    {
        $this->insert(1, 'failed', 0, $maxAttempts, '2026-01-01 11:59:00');

        $sends = 0;

        for ($i = 0; $i < $maxAttempts + 3; $i++) {
            // A send happens, then the worker releases the lease and schedules a retry.
            if ($this->claim(1, 'worker-'.$i) === 1) {
                $sends++;
                $this->release(1);
            }
        }

        self::assertSame($maxAttempts, $sends, "max_attempts={$maxAttempts} must permit exactly that many sends");
    }

    public function test_a_crash_after_claim_still_consumes_the_attempt(): void
    {
        $this->insert(1, 'failed', 0, 2, '2026-01-01 11:59:00');

        // Send 1 claims, then the process dies before any response is handled:
        // claimed_at stays set and attempt stays consumed.
        self::assertSame(1, $this->claim(1, 'crashed-worker'));
        self::assertSame(1, (int) $this->row(1)['attempt']);

        // The lease expires and a new worker recovers the row for send 2.
        $this->pdo->exec("UPDATE webhook_deliveries SET claimed_at = '2026-01-01 11:00:00' WHERE id = 1");
        self::assertSame(1, $this->claim(1, 'recovery-worker'));
        self::assertSame(2, (int) $this->row(1)['attempt']);

        // Budget exhausted: no third send, even after another lease expiry.
        $this->pdo->exec("UPDATE webhook_deliveries SET claimed_at = '2026-01-01 11:00:00' WHERE id = 1");
        self::assertSame(0, $this->claim(1, 'third-worker'));
    }

    /**
     * Rows written before Checkpoint I start at attempt = 1 under the old
     * "current attempt number" meaning, which is already "one attempt consumed"
     * under the new meaning — so their remaining budget is preserved as-is.
     */
    public function test_legacy_rows_created_under_the_old_attempt_semantics_keep_their_budget(): void
    {
        // Pre-Checkpoint-I rows start at attempt = 1.
        $this->insert(1, 'failed', 1, 3, '2026-01-01 11:59:00');

        self::assertSame(1, $this->claim(1, 'worker-a'));
        self::assertSame(2, (int) $this->row(1)['attempt']);

        // `attempt = 1` already means one consumed attempt, so two sends remain.
        $this->release(1);
        self::assertSame(1, $this->claim(1, 'worker-b'));
        $this->release(1);
        self::assertSame(0, $this->claim(1, 'worker-c'), 'The preserved budget is not extended');
    }

    /**
     * A row stranded by the old code before its first send is recoverable; it is
     * conservatively treated as having spent its attempt rather than granted a
     * free one, because SQL cannot tell it apart from an already-consumed claim.
     */
    public function test_a_legacy_unclaimed_pending_row_is_recovered_conservatively(): void
    {
        $this->insert(1, 'pending', 1, 3, '2026-01-01 11:00:00', null);

        self::assertSame(1, $this->claim(1, 'recovery-worker'), 'The stranded row is recovered');

        $row = $this->row(1);
        self::assertSame('pending', $row['status'], 'Its status is left untouched');
        self::assertSame(2, (int) $row['attempt'], 'The recoverable claim consumes one attempt');
    }

    public function test_attempt_budget_cannot_be_exceeded_however_many_workers_race(): void
    {
        $this->insert(1, 'failed', 0, 3, '2026-01-01 11:59:00');

        self::assertSame(1, $this->claim(1, 'a'), 'Send 1 of 3');
        // Lease is live, so this must fail on the lease, not the budget.
        self::assertSame(0, $this->claim(1, 'b'));

        // Two more sends are permitted...
        $this->release(1);
        self::assertSame(1, $this->claim(1, 'c'), 'Send 2 of 3');
        $this->release(1);
        self::assertSame(1, $this->claim(1, 'd'), 'Send 3 of 3');

        // ...and then the budget is exhausted, so a stale lease cannot buy a fourth.
        $this->release(1);
        self::assertSame(0, $this->claim(1, 'e'), 'attempt == max_attempts is terminal');
    }

    public function test_a_fresh_pending_row_is_not_reclaimed_prematurely(): void
    {
        $this->insert(1, 'pending', 0, 3, '2026-01-01 12:00:00', null);

        self::assertSame(0, $this->claim(1, 'worker-a'), 'A row created moments ago belongs to the inline attempt');
    }

    public function test_an_abandoned_pending_row_is_recovered(): void
    {
        // Crash between INSERT and the claim: pending, never claimed, long past.
        $this->insert(1, 'pending', 0, 3, '2026-01-01 11:50:00', null);

        self::assertSame(1, $this->claim(1, 'recovery-worker'), 'Defect 2: the stranded row must be recoverable');
        self::assertSame('recovery-worker', $this->row(1)['claim_token']);
    }

    public function test_an_expired_processing_lease_is_reclaimable_but_a_live_one_is_not(): void
    {
        $this->insert(1, 'pending', 1, 3, '2026-01-01 12:00:00', '2026-01-01 11:59:30');
        self::assertSame(0, $this->claim(1, 'other'), 'A live lease must not be stolen');

        $this->pdo->exec("UPDATE webhook_deliveries SET claimed_at = '2026-01-01 11:50:00' WHERE id = 1");
        self::assertSame(1, $this->claim(1, 'other'), 'A lease that ran out is recoverable');
    }

    public function test_a_stale_worker_cannot_finalize_a_reclaimed_row(): void
    {
        $this->insert(1, 'failed', 0, 3, '2026-01-01 11:59:00');

        self::assertSame(1, $this->claim(1, 'slow-worker'));

        // The lease expires and a second worker takes over.
        $this->pdo->exec("UPDATE webhook_deliveries SET claimed_at = '2026-01-01 11:00:00' WHERE id = 1");
        self::assertSame(1, $this->claim(1, 'new-worker'));

        // The slow worker's late response arrives and tries to finalize.
        $stale = $this->pdo->prepare("UPDATE webhook_deliveries SET status = 'success' WHERE id = 1 AND claim_token = :token");
        $stale->execute([':token' => 'slow-worker']);
        self::assertSame(0, $stale->rowCount(), 'A stale owner must not overwrite the newer claim');

        $current = $this->pdo->prepare("UPDATE webhook_deliveries SET status = 'success' WHERE id = 1 AND claim_token = :token");
        $current->execute([':token' => 'new-worker']);
        self::assertSame(1, $current->rowCount(), 'The current owner finalizes normally');
        self::assertSame('success', $this->row(1)['status']);
    }

    public function test_a_successful_row_is_never_claimable_again(): void
    {
        $this->insert(1, 'success', 1, 3, '2026-01-01 11:00:00');

        self::assertSame(0, $this->claim(1, 'worker-a'));
    }

    /**
     * Checkpoint J, the defect this file's recovery pass exists for.
     *
     * A worker claims the third and final permitted attempt (consuming it), then
     * dies before writing any HTTP result. Because the budget is spent, the retry
     * predicate cannot reach the row any more — so without a dedicated recovery
     * pass the row keeps a dead claim token, never records `processed_at`, and its
     * final failure is never counted against the endpoint.
     *
     * This test pins the stranded state itself, which is why the recovery pass is
     * needed rather than a widening of the retry predicate: widening that
     * predicate would buy a fourth send.
     */
    public function test_the_retry_predicate_cannot_reach_a_claim_abandoned_with_the_budget_spent(): void
    {
        $this->insert(1, 'failed', 3, 3, '2026-01-01 11:00:00', '2026-01-01 11:00:00', 'dead-worker', null, 1);

        self::assertSame(0, $this->claim(1, 'recovery-worker'), 'The budget is spent, so the row is not claimable');

        $row = $this->row(1);
        self::assertNull($row['processed_at'], 'Stranded: no terminal outcome is ever recorded');
        self::assertSame('dead-worker', $row['claim_token'], 'Stranded: the dead claim is never released');
        self::assertSame(3, (int) $row['attempt'], 'The consumed budget is not re-based');
        self::assertSame(0, (int) $this->endpoint()['failure_count'], 'Stranded: the final failure is never counted');
    }

    public function test_an_abandoned_claim_with_the_budget_spent_is_finalized_exactly_once(): void
    {
        $this->insert(1, 'failed', 3, 3, '2026-01-01 11:00:00', '2026-01-01 11:00:00', 'dead-worker', null, 1);

        self::assertSame(1, $this->recover(), 'The recovery pass finalizes the stranded row');

        $row = $this->row(1);
        self::assertSame('failed', $row['status'], 'It ends as a terminal failure, not as pending work');
        self::assertSame('2026-01-01 12:00:00', $row['processed_at']);
        self::assertSame(self::ABANDONED_MESSAGE, $row['error_message']);
        self::assertNull($row['claim_token'], 'The dead claim is released');
        self::assertNull($row['claimed_at']);
        self::assertSame(3, (int) $row['attempt'], 'Recovery never consumes another attempt');

        // A second tick (or a second worker) must find nothing left to finalize.
        self::assertSame(0, $this->recover(), 'Finalization is exactly-once');
    }

    public function test_the_endpoint_failure_total_is_incremented_exactly_once_by_recovery(): void
    {
        $this->insert(1, 'failed', 3, 3, '2026-01-01 11:00:00', '2026-01-01 11:00:00', 'dead-worker', null, 1);

        self::assertSame(1, $this->recover());
        self::assertSame(0, $this->recover());
        self::assertSame(0, $this->recover());

        $endpoint = $this->endpoint();
        self::assertSame(1, (int) $endpoint['failure_count'], 'The final failure is counted once, not once per tick');
        self::assertSame('2026-01-01 12:00:00', $endpoint['last_failed_at']);
    }

    /**
     * The exactly-once property must come from the ownership CAS, not from ticks
     * happening to run one after another. Here both passes select the stranded row
     * before either writes — the interleaving a second worker or an overlapping
     * scheduler run produces — and only the first may finalize and count it.
     */
    public function test_two_passes_that_preselect_the_same_row_finalize_it_once(): void
    {
        $this->insert(1, 'failed', 3, 3, '2026-01-01 11:00:00', '2026-01-01 11:00:00', 'dead-worker', null, 1);

        $firstPass = $this->recoverCandidates();
        $secondPass = $this->recoverCandidates();

        self::assertCount(1, $firstPass, 'Both passes see the same stranded row');
        self::assertCount(1, $secondPass);

        self::assertTrue($this->finalizeCandidate($firstPass[0]), 'The first pass wins the CAS');
        self::assertFalse($this->finalizeCandidate($secondPass[0]), 'The second pass must lose it');

        self::assertSame(1, (int) $this->endpoint()['failure_count'], 'Only the winner counts the failure');
        self::assertSame('2026-01-01 12:00:00', $this->row(1)['processed_at']);
    }

    /**
     * The service commits each finalization together with its endpoint counter inside
     * one DB::transaction(), and the panel's test database is already inside a
     * transaction (RefreshDatabase), so Laravel nests it as a SAVEPOINT.
     *
     * Scope: this is engine-level evidence on the panel's SQLite engine — a failed
     * second statement rolls the first one back and leaves the connection usable, so
     * the row stays retryable instead of becoming a finalized delivery the endpoint
     * never counted. It is deliberately NOT evidence about the service itself; that
     * path is covered by the Filament feature tests, which cannot run on this machine.
     */
    public function test_a_failed_endpoint_write_rolls_the_finalization_back(): void
    {
        $this->insert(1, 'failed', 3, 3, '2026-01-01 11:00:00', '2026-01-01 11:00:00', 'dead-worker', null, 1);

        // The outer transaction RefreshDatabase keeps open for the whole test.
        $this->pdo->beginTransaction();

        $candidate = $this->recoverCandidates()[0];

        // Laravel's nesting form: SAVEPOINT / ROLLBACK TO SAVEPOINT.
        $this->pdo->exec('SAVEPOINT outcome');

        // First statement of the pair: the ownership CAS.
        $cas = $this->pdo->prepare(self::RECOVER_SQL);
        $cas->execute([
            ':message' => self::ABANDONED_MESSAGE,
            ':now' => $this->now->format('Y-m-d H:i:s'),
            ':id' => $candidate['id'],
            ':token' => $candidate['claim_token'],
        ]);
        self::assertSame(1, $cas->rowCount(), 'The CAS itself succeeds');
        self::assertSame('2026-01-01 12:00:00', $this->row(1)['processed_at']);

        // Second statement of the pair fails, the way an unavailable endpoint table
        // would (a plain UPDATE cannot be made to fail on demand).
        $failed = false;

        try {
            $this->pdo->exec('UPDATE webhook_endpoints SET unavailable_column = 1 WHERE id = 1');
        } catch (\PDOException) {
            $failed = true;
            $this->pdo->exec('ROLLBACK TO SAVEPOINT outcome');
        }

        self::assertTrue($failed, 'The second statement must fail for this test to prove anything');

        $row = $this->row(1);
        self::assertNull($row['processed_at'], 'The pair rolls back together');
        self::assertSame('dead-worker', $row['claim_token'], 'The restored claim keeps the row retryable');
        self::assertSame('2026-01-01 11:00:00', $row['claimed_at']);
        self::assertSame(3, (int) $row['attempt'], 'The consumed budget is not restored');
        self::assertSame(0, (int) $this->endpoint()['failure_count'], 'The counter rolls back with it');

        // The outer transaction survives, so the next pass closes the row out once.
        self::assertSame(1, $this->recover());
        self::assertSame('2026-01-01 12:00:00', $this->row(1)['processed_at']);
        self::assertSame(1, (int) $this->endpoint()['failure_count']);

        $this->pdo->rollBack();
    }

    public function test_a_live_lease_is_never_finalized_by_the_recovery_pass(): void
    {
        // Same exhausted state, but the worker is still inside its lease.
        $this->insert(1, 'failed', 3, 3, '2026-01-01 11:00:00', '2026-01-01 11:59:30', 'live-worker', null, 1);

        self::assertSame(0, $this->recover(), 'A running worker keeps its claim until the lease expires');

        $row = $this->row(1);
        self::assertNull($row['processed_at']);
        self::assertSame('live-worker', $row['claim_token']);
        self::assertSame(0, (int) $this->endpoint()['failure_count']);
    }

    public function test_recovery_leaves_a_claim_with_budget_left_to_the_normal_retry_path(): void
    {
        // The worker died too, but two sends remain, so this row is retried.
        $this->insert(1, 'failed', 1, 3, '2026-01-01 11:00:00', '2026-01-01 11:00:00', 'dead-worker', null, 1);

        self::assertSame(0, $this->recover(), 'A row with budget is not a terminal failure');

        self::assertSame(1, $this->claim(1, 'retry-worker'), 'The ordinary retry path still reclaims it');
        $row = $this->row(1);
        self::assertNull($row['processed_at'], 'It stays retryable');
        self::assertSame(2, (int) $row['attempt'], 'Reclaiming consumes exactly one attempt');
    }

    public function test_recovery_never_grants_another_send(): void
    {
        $this->insert(1, 'failed', 3, 3, '2026-01-01 11:00:00', '2026-01-01 11:00:00', 'dead-worker', null, 1);

        self::assertSame(1, $this->recover());

        // Everything a claim needs to succeed except the budget.
        self::assertSame(0, $this->claim(1, 'greedy-worker'), 'The exhausted budget still binds after recovery');
        self::assertSame(3, (int) $this->row(1)['attempt']);
    }

    public function test_a_stale_owner_cannot_overwrite_the_recovered_finalization(): void
    {
        $this->insert(1, 'failed', 3, 3, '2026-01-01 11:00:00', '2026-01-01 11:00:00', 'dead-worker', null, 1);

        self::assertSame(1, $this->recover());

        // The "dead" worker turns out to be merely slow and now writes a success.
        self::assertSame(0, $this->finalizeAsWorker(1, 'dead-worker', 'success'), 'Its late result is discarded');

        $row = $this->row(1);
        self::assertSame('failed', $row['status'], 'The terminal failure stands');
        self::assertSame(self::ABANDONED_MESSAGE, $row['error_message']);
        self::assertSame('2026-01-01 12:00:00', $row['processed_at']);

        // A live worker that legitimately owns a claim still finalizes normally.
        $this->insert(2, 'failed', 0, 3, '2026-01-01 11:00:00', null, null, null, 1);
        self::assertSame(1, $this->claim(2, 'live-worker'));
        self::assertSame(1, $this->finalizeAsWorker(2, 'live-worker', 'success'));
    }

    public function test_a_legacy_row_without_a_claim_token_is_never_touched_by_recovery(): void
    {
        // Pre-Claimpoint-H rows have no claim columns at all, so `attempt` alone
        // cannot prove a worker died holding this row.
        $this->insert(1, 'failed', 3, 3, '2026-01-01 11:00:00', null, null, null, 1);

        self::assertSame(0, $this->recover());

        $row = $this->row(1);
        self::assertNull($row['processed_at'], 'Its state is left exactly as the old code wrote it');
        self::assertSame(0, (int) $this->endpoint()['failure_count']);
    }

    public function test_a_crash_on_the_initial_send_of_a_single_attempt_budget_is_finalized(): void
    {
        // max_attempts = 1 is reachable from the settings form, and the inline
        // initial send is the only send it permits.
        $this->insert(1, 'pending', 1, 1, '2026-01-01 11:00:00', '2026-01-01 11:00:00', 'dead-worker', null, 1);

        self::assertSame(1, $this->recover());

        $row = $this->row(1);
        self::assertSame('failed', $row['status'], 'A pending row whose only attempt died is a terminal failure');
        self::assertSame('2026-01-01 12:00:00', $row['processed_at']);
        self::assertNull($row['claim_token']);
        self::assertSame(1, (int) $this->endpoint()['failure_count']);
    }
}
