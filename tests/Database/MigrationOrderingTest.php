<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Database;

use PHPUnit\Framework\TestCase;

final class MigrationOrderingTest extends TestCase
{
    /**
     * @return array<string, string> map of prefix => filename
     */
    private function migrations(): array
    {
        $files = glob(__DIR__.'/../../database/migrations/*.php');
        sort($files);

        $map = [];
        foreach ($files as $file) {
            $basename = basename($file);
            preg_match('/^(\d+)_/', $basename, $m);
            $map[$m[1] ?? ''] = $basename;
        }

        return $map;
    }

    public function test_migration_prefixes_are_unique(): void
    {
        $migrations = $this->migrations();

        $this->assertCount(
            count(array_unique(array_keys($migrations))),
            $migrations,
            'Duplicate migration prefixes found (regression for the duplicate 007 prefix fixed in R8)'
        );
    }

    public function test_migration_prefixes_are_sequential_and_ordered(): void
    {
        $prefixes = array_keys($this->migrations());

        $expected = range(1, count($prefixes));
        $expected = array_map(fn ($i) => str_pad((string) $i, 3, '0', STR_PAD_LEFT), $expected);

        $this->assertSame($expected, $prefixes, 'Migration prefixes must be unique and ascending 001..N');
    }

    public function test_webhook_deliveries_migration_runs_after_endpoints_table(): void
    {
        // 005 adds a FK to webhook_endpoints, created in 004.
        $migrations = $this->migrations();

        $endpointsPrefix = (int) array_search('004_create_webhook_endpoints_table.php', $migrations, true);
        $deliveriesPrefix = (int) array_search('005_create_webhook_deliveries_table.php', $migrations, true);

        $this->assertGreaterThan($endpointsPrefix, $deliveriesPrefix);
    }

    public function test_idempotency_update_runs_after_idempotency_table(): void
    {
        // 008 alters notification_idempotency, created in 006.
        $migrations = $this->migrations();

        $tablePrefix = (int) array_search('006_create_notification_idempotency_table.php', $migrations, true);
        $updatePrefix = (int) array_search('008_update_notification_idempotency_table.php', $migrations, true);

        $this->assertGreaterThan($tablePrefix, $updatePrefix);
    }

    public function test_suspension_reason_migration_runs_after_expiry_warning_day(): void
    {
        // 007 places suspension_reason after('expiry_warning_day') created in 002.
        $migrations = $this->migrations();

        $warningPrefix = (int) array_search('002_add_expiry_warning_day_to_servers_table.php', $migrations, true);
        $reasonPrefix = (int) array_search('007_add_suspension_reason_to_servers_table.php', $migrations, true);

        $this->assertGreaterThan($warningPrefix, $reasonPrefix);
    }

    public function test_v120_migration_names_are_preserved(): void
    {
        // Renaming 001/002 would re-run them against upgraded panels that
        // already recorded the v1.2.0 migration names.
        $migrations = $this->migrations();

        $this->assertArrayHasKey('001_add_expires_at_to_servers_table.php', array_flip($migrations));
        $this->assertArrayHasKey('002_add_expiry_warning_day_to_servers_table.php', array_flip($migrations));
    }
}
