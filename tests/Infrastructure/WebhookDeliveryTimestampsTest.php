<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;

/**
 * H6.3 E2E regression guard: migration 005 creates webhook_deliveries
 * WITHOUT created_at/updated_at columns (timing lives in the explicit
 * queued_at/processed_at/next_attempt_at columns). With Eloquent timestamps
 * left enabled, every delivery INSERT/UPDATE emitted the nonexistent columns
 * and failed with "Unknown column 'updated_at' in 'field list'" — the queue
 * step threw, WebhookService swallowed it, and no webhook could ever be
 * delivered (observed live on the DEV panel; delivery signing itself was
 * unaffected because it never reached the DB).
 */
final class WebhookDeliveryTimestampsTest extends TestCase
{
    private const MODEL = __DIR__.'/../../src/Infrastructure/Webhooks/Models/WebhookDelivery.php';

    private const MIGRATION = __DIR__.'/../../database/migrations/005_create_webhook_deliveries_table.php';

    public function test_delivery_model_disables_eloquent_timestamps(): void
    {
        $model = (string) file_get_contents(self::MODEL);

        $this->assertMatchesRegularExpression(
            '/public \$timestamps = false;/',
            $model,
            'WebhookDelivery must disable Eloquent timestamps — migration 005 has no created_at/updated_at columns, so INSERT/UPDATE with them always fails'
        );
    }

    public function test_migration_005_creates_no_timestamp_columns(): void
    {
        $migration = (string) file_get_contents(self::MIGRATION);

        $this->assertStringNotContainsString(
            '$table->timestamps()',
            $migration,
            'If migration 005 ever gains timestamp columns, revisit the model fix deliberately'
        );
        $this->assertStringContainsString(
            "'queued_at'",
            $migration,
            'The explicit queued_at timing column must remain the delivery-time source of truth'
        );
    }
}
