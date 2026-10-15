<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Console;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * H(P0-3) regression guard: the notification-idempotency claim predicate must be
 * portable.
 *
 * The predicate was written as raw MySQL:
 *
 *     orWhereRaw("status = 'processing' AND last_attempt_at < NOW() - INTERVAL 5 MINUTE")
 *
 * On SQLite that raises `near "5": syntax error` (verified directly:
 * `PDOException: SQLSTATE[HY000]: General error: 1 near "5": syntax error`),
 * aborting the whole lifecycle command. Not hypothetical — Pelican's own test
 * configuration runs on SQLite (`phpunit.xml`: DB_CONNECTION=sqlite,
 * DB_DATABASE=testing.sqlite), so this breaks the moment plugin tests run inside
 * the panel.
 *
 * The plugin test harness has no Laravel (`illuminate/database` is supplied by the
 * panel, not by this package), so the predicate is reproduced as plain SQL: after
 * the fix every value is a bound parameter, so this IS the SQL the builder emits.
 * {@see test_predicate_source_contains_no_engine_specific_sql()} fails if the
 * production implementation drifts back to engine-specific SQL, which is what
 * keeps the reproduction honest.
 *
 * The `UNIQUE (server_id, notification_type, identifier)` constraint from
 * migration 006 means a server/type/identifier triple can only exist once, so each
 * lifecycle state is seeded under its own server id.
 */
final class ClaimNotificationPortabilityTest extends TestCase
{
    /** The crash-recovery window, mirroring the command's STALE_PROCESSING_MINUTES. */
    private const STALE_MINUTES = 5;

    private const TYPE = 'expiry_warning';

    private const IDENTIFIER = '7';

    /**
     * The predicate as the query builder now emits it: three scoping equals plus a
     * grouped triple, with the stale cutoff bound.
     */
    private const PREDICATE = <<<'SQL'
        server_id = ?
        AND notification_type = ?
        AND identifier = ?
        AND (
            status = 'pending'
            OR status = 'failed'
            OR (status = 'processing' AND last_attempt_at < ?)
        )
        SQL;

    public function test_predicate_runs_on_sqlite(): void
    {
        $pdo = $this->database();
        $this->seed($pdo);

        $this->assertIsArray(
            $this->claimableIdsFor($pdo, '1'),
            'The predicate must execute on SQLite without a syntax error'
        );
    }

    public function test_claimable_states_are_pending_failed_and_stale_processing(): void
    {
        $pdo = $this->database();

        $this->seedOne($pdo, 'pending', now()->modify('-1 minutes'), '1');
        $this->seedOne($pdo, 'failed', now()->modify('-1 minutes'), '2');
        $this->seedOne($pdo, 'processing', now()->modify('-30 minutes'), '3'); // crashed worker
        $this->seedOne($pdo, 'processing', now(), '4');                        // live worker
        $this->seedOne($pdo, 'sent', now()->modify('-30 minutes'), '5');

        $this->assertSame(
            ['1', '2', '3'],
            $this->claimableServerIds($pdo),
            'pending, failed and stale-processing rows are claimable; sent and fresh-processing are not'
        );
    }

    public function test_sent_rows_are_never_reclaimed(): void
    {
        $pdo = $this->database();

        $this->assertNotContains(
            $this->seedOne($pdo, 'sent', now()->modify('-30 minutes'), '4217'),
            $this->claimableIdsFor($pdo, '4217'),
            'A notification already sent must never be re-sent for the same triple'
        );
    }

    public function test_freshly_processing_rows_are_not_stolen_from_a_live_worker(): void
    {
        $pdo = $this->database();

        $this->assertNotContains(
            $this->seedOne($pdo, 'processing', now(), '4217'),
            $this->claimableIdsFor($pdo, '4217'),
            'A row a live worker is processing must not be claimed concurrently'
        );
    }

    public function test_stale_processing_rows_are_recovered_after_a_worker_crash(): void
    {
        $pdo = $this->database();

        $this->assertContains(
            $this->seedOne($pdo, 'processing', now()->modify('-30 minutes'), '4217'),
            $this->claimableIdsFor($pdo, '4217'),
            'A row left in processing by a dead worker must be reclaimed once the window elapses'
        );
    }

    public function test_window_boundary_fresh_rows_are_not_yet_stale(): void
    {
        $pdo = $this->database();

        // Four minutes old: still inside the five-minute window, so still owned by
        // the worker that claimed it.
        $this->assertNotContains(
            $this->seedOne($pdo, 'processing', now()->modify('-4 minutes'), '4217'),
            $this->claimableIdsFor($pdo, '4217'),
            'A row younger than the stale window must not be reclaimed'
        );
    }

    public function test_claim_is_scoped_to_one_server_type_and_identifier(): void
    {
        $pdo = $this->database();

        $wanted = $this->seedOne($pdo, 'pending', now(), '4217');
        $otherServer = $this->seedOne($pdo, 'pending', now(), '9999');

        $claimed = $this->claimableIdsFor($pdo, '4217');

        $this->assertSame([$wanted], $claimed, 'The claim must match the exact server triple only');
        $this->assertNotContains($otherServer, $claimed, 'Another server must not be claimed');
    }

    /**
     * The anti-drift guard: the production predicate must not reintroduce
     * engine-specific SQL. Without this the assertions above would keep passing
     * while the shipped implementation regressed.
     */
    public function test_predicate_source_contains_no_engine_specific_sql(): void
    {
        $body = $this->claimPredicateSource();

        foreach (['INTERVAL', 'NOW()', 'orWhereRaw', 'whereRaw', 'DATE_ADD', 'strftime', 'datetime('] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $body,
                "The claim predicate must stay portable; found engine-specific token '{$forbidden}'"
            );
        }

        // The stale cutoff must be computed in PHP and bound, not computed by SQL.
        $this->assertStringContainsString(
            "->where('last_attempt_at', '<', now()->subMinutes(self::STALE_PROCESSING_MINUTES))",
            $body,
            'The stale cutoff must be computed in PHP and bound as a parameter'
        );
    }

    /**
     * The duplicate-key guard must not depend on a string-vs-int comparison, and
     * must cover the portable SQLSTATE class.
     */
    public function test_duplicate_key_detection_is_portable(): void
    {
        $source = $this->commandSource();

        $this->assertStringNotContainsString(
            "getCode() === '23000'",
            $this->stripComments($source),
            'A strict comparison against a quoted code breaks when PDO surfaces the SQLSTATE as an int'
        );
        $this->assertStringNotContainsString(
            'getCode() === 23000',
            $this->stripComments($source),
            'A strict comparison against an int code breaks when PDO surfaces the SQLSTATE as a string'
        );
        $this->assertStringContainsString('isUniqueConstraintViolation', $source);
        $this->assertStringContainsString(
            '(string) $exception->getCode()',
            $source,
            'The SQLSTATE must be normalized to a string before comparison'
        );
        $this->assertStringContainsString("'23000'", $source, 'MySQL/SQLite duplicate-key SQLSTATE must be covered');
        $this->assertStringContainsString("'23505'", $source, 'PostgreSQL unique_violation must be covered');
    }

    /**
     * @return array<int, mixed>
     */
    private function bindings(string $serverId): array
    {
        return [
            $serverId,
            self::TYPE,
            self::IDENTIFIER,
            now()->modify('-'.self::STALE_MINUTES.' minutes')->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Ids claimable for one server, using the production predicate's shape.
     *
     * @return int[]
     */
    private function claimableIdsFor(PDO $pdo, string $serverId): array
    {
        $statement = $pdo->prepare('SELECT id FROM notification_idempotency WHERE '.self::PREDICATE);
        $statement->execute($this->bindings($serverId));

        return array_map(intval(...), $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Server ids whose row is claimable, ignoring the per-server scope so all
     * seeded states can be classified in one assertion.
     *
     * @return string[]
     */
    private function claimableServerIds(PDO $pdo): array
    {
        $statement = $pdo->prepare(
            "SELECT server_id FROM notification_idempotency
             WHERE notification_type = ?
               AND identifier = ?
               AND (
                   status = 'pending'
                   OR status = 'failed'
                   OR (status = 'processing' AND last_attempt_at < ?)
               )
             ORDER BY server_id"
        );
        $statement->execute([
            self::TYPE,
            self::IDENTIFIER,
            now()->modify('-'.self::STALE_MINUTES.' minutes')->format('Y-m-d H:i:s'),
        ]);

        return array_map(strval(...), $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function database(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Mirrors migration 006 (create) plus migration 008 (extend). SQLite has no
        // ENUM, so the status domain is TEXT + CHECK.
        $pdo->exec(<<<'SQL'
            CREATE TABLE notification_idempotency (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                server_id VARCHAR NOT NULL,
                notification_type VARCHAR NOT NULL,
                identifier VARCHAR NOT NULL,
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                status VARCHAR NOT NULL DEFAULT 'pending'
                    CHECK (status IN ('pending','processing','sent','failed')),
                attempts INTEGER NOT NULL DEFAULT 0,
                last_attempt_at DATETIME NULL,
                UNIQUE (server_id, notification_type, identifier)
            )
            SQL);

        return $pdo;
    }

    /**
     * Insert one row for the triple and return its id.
     */
    private function seedOne(
        PDO $pdo,
        string $status,
        \DateTimeImmutable $lastAttemptAt,
        string $serverId = '4217'
    ): int {
        $pdo->prepare(
            'INSERT INTO notification_idempotency (server_id, notification_type, identifier, status, attempts, last_attempt_at) VALUES (?,?,?,?,?,?)'
        )->execute([$serverId, self::TYPE, self::IDENTIFIER, $status, 1, $lastAttemptAt->format('Y-m-d H:i:s')]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Seed one server per lifecycle state, matching the claim predicate's scope.
     */
    private function seed(PDO $pdo): void
    {
        $this->seedOne($pdo, 'pending', now()->modify('-1 minutes'), '1');
        $this->seedOne($pdo, 'failed', now()->modify('-1 minutes'), '2');
        $this->seedOne($pdo, 'processing', now()->modify('-30 minutes'), '3'); // crashed worker
        $this->seedOne($pdo, 'processing', now(), '4');                        // live worker
        $this->seedOne($pdo, 'sent', now()->modify('-30 minutes'), '5');
    }
    private function commandSource(): string
    {
        return (string) file_get_contents(
            __DIR__.'/../../src/Console/Commands/ProcessServerExpirationCommand.php'
        );
    }

    private function claimPredicateSource(): string
    {
        $source = $this->commandSource();
        $start = strpos($source, 'public static function scopeClaimableNotifications');

        $this->assertNotFalse($start, 'The claimable predicate must exist and be independently testable');

        return substr($source, $start, 1200);
    }

    /**
     * Remove comments so documentation text cannot satisfy (or trip) an assertion.
     */
    private function stripComments(string $source): string
    {
        return implode('', array_map(
            static fn (array|string $token): string => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                ? ''
                : (is_array($token) ? $token[1] : $token),
            token_get_all($source)
        ));
    }
}
