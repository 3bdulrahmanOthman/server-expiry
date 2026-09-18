<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;

/**
 * Guards the configurable webhook delivery parameters (v2.1, H1).
 *
 * Retry attempts, HTTP timeout and backoff base were previously hardcoded
 * (MAX_ATTEMPTS = 3, Http::timeout(10), BASE_DELAY_SECONDS = 1), which made
 * delivery behavior untunable and invisible in the plugin settings.
 */
final class WebhookDeliverySettingsTest extends TestCase
{
    private const SERVICE = __DIR__.'/../../src/Infrastructure/Webhooks/WebhookDeliveryService.php';

    private const COMMAND = __DIR__.'/../../src/Console/Commands/ProcessWebhookDeliveriesCommand.php';

    private const PROVIDER = __DIR__.'/../../src/Providers/ServerExpiryServiceProvider.php';

    private const CONFIG = __DIR__.'/../../config/server-expiry.php';

    public function test_delivery_service_reads_configurable_attempts(): void
    {
        $source = $this->source(self::SERVICE);

        $this->assertStringContainsString(
            "config('server-expiry.webhook_max_attempts'",
            $source,
            'Delivery attempts must come from the webhook_max_attempts config key'
        );
        $this->assertStringNotContainsString('self::MAX_ATTEMPTS', $source);
    }

    public function test_delivery_service_reads_configurable_timeout(): void
    {
        $source = $this->source(self::SERVICE);

        $this->assertStringContainsString(
            "config('server-expiry.webhook_timeout_seconds'",
            $source,
            'The HTTP timeout must come from the webhook_timeout_seconds config key'
        );
        $this->assertStringNotContainsString('Http::timeout(10)', $source);
    }

    public function test_delivery_service_reads_configurable_backoff_base(): void
    {
        $source = $this->source(self::SERVICE);

        $this->assertStringContainsString(
            "config('server-expiry.webhook_backoff_base_seconds'",
            $source,
            'The retry backoff base must come from the webhook_backoff_base_seconds config key'
        );
        $this->assertStringNotContainsString('self::BASE_DELAY_SECONDS', $source);
    }

    public function test_config_declares_webhook_delivery_keys_with_v201_defaults(): void
    {
        $source = $this->source(self::CONFIG);

        foreach ([
            "'webhook_max_attempts' => env('SERVER_EXPIRY_WEBHOOK_MAX_ATTEMPTS', 3)",
            "'webhook_timeout_seconds' => env('SERVER_EXPIRY_WEBHOOK_TIMEOUT', 10)",
            "'webhook_backoff_base_seconds' => env('SERVER_EXPIRY_WEBHOOK_BACKOFF_BASE', 1)",
            "'support_url' => env('SERVER_EXPIRY_SUPPORT_URL', '')",
        ] as $key) {
            $this->assertStringContainsString($key, $source, "Missing config entry: {$key}");
        }
    }

    public function test_retry_command_orchestrates_the_delivery_service(): void
    {
        $source = $this->source(self::COMMAND);

        $this->assertStringContainsString('pelican:process-webhook-deliveries', $source);
        $this->assertStringContainsString('processPendingDeliveries', $source);
    }

    public function test_scheduler_runs_the_retry_command_every_minute(): void
    {
        $source = $this->source(self::PROVIDER);

        $this->assertStringContainsString('ProcessWebhookDeliveriesCommand::class', $source);
        $this->assertMatchesRegularExpression(
            '/Schedule::command\(ProcessWebhookDeliveriesCommand::class\)\s*->everyMinute\(\)\s*->withoutOverlapping\(\)/s',
            $source,
            'The webhook retry command must be scheduled every minute without overlapping'
        );
    }

    private function source(string $path): string
    {
        return (string) file_get_contents($path);
    }
}
