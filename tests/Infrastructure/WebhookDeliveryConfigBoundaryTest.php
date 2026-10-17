<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Infrastructure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\WebhookDeliveryService;

use function clear_test_config;
use function set_test_config;

/**
 * Behavioral boundary tests for the webhook delivery tuning values.
 *
 * The `.env` values arrive as strings and an operator can set them to 0, a
 * negative number or nonsense. Each must clamp to a usable lower bound so a
 * delivery can never end up with zero attempts or a zero-second timeout.
 *
 * WebhookDeliverySettingsTest covers the source contract (the config keys are
 * read rather than hardcoded); this test covers the resulting values.
 */
final class WebhookDeliveryConfigBoundaryTest extends TestCase
{
    protected function tearDown(): void
    {
        clear_test_config();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: int|string}>
     */
    public static function unusableValues(): array
    {
        return [
            'integer zero' => [0],
            'string zero' => ['0'],
            'integer negative' => [-1],
            'string negative' => ['-5'],
            'non-numeric' => ['abc'],
            'empty string' => [''],
            'whitespace' => ['  '],
        ];
    }

    /**
     * @return array<string, array{0: int|string, 1: int}>
     */
    public static function usableValues(): array
    {
        return [
            'default attempts' => [3, 3],
            'string attempts' => ['10', 10],
            'timeout' => [30, 30],
            'string timeout' => ['60', 60],
        ];
    }

    /**
     * @param  int|string  $value
     */
    #[DataProvider('unusableValues')]
    public function test_max_attempts_never_drops_below_one($value): void
    {
        set_test_config(['server-expiry.webhook_max_attempts' => $value]);

        self::assertSame(
            1,
            $this->read('maxAttempts'),
            'A delivery must always be attempted at least once'
        );
    }

    /**
     * @param  int|string  $value
     */
    #[DataProvider('unusableValues')]
    public function test_timeout_never_drops_below_one_second($value): void
    {
        set_test_config(['server-expiry.webhook_timeout_seconds' => $value]);

        self::assertSame(1, $this->read('timeoutSeconds'));
    }

    /**
     * @param  int|string  $value
     */
    #[DataProvider('unusableValues')]
    public function test_backoff_base_never_drops_below_one_second($value): void
    {
        set_test_config(['server-expiry.webhook_backoff_base_seconds' => $value]);

        self::assertSame(
            1,
            $this->read('backoffBaseSeconds'),
            'A zero base would collapse the exponential backoff to an immediate retry storm'
        );
    }

    public function test_usable_values_pass_through_unchanged(): void
    {
        set_test_config([
            'server-expiry.webhook_max_attempts' => '10',
            'server-expiry.webhook_timeout_seconds' => '60',
            'server-expiry.webhook_backoff_base_seconds' => 5,
        ]);

        self::assertSame(10, $this->read('maxAttempts'));
        self::assertSame(60, $this->read('timeoutSeconds'));
        self::assertSame(5, $this->read('backoffBaseSeconds'));
    }

    public function test_defaults_apply_when_config_is_absent(): void
    {
        self::assertSame(3, $this->read('maxAttempts'));
        self::assertSame(10, $this->read('timeoutSeconds'));
        self::assertSame(1, $this->read('backoffBaseSeconds'));
    }

    /**
     * The retry delay is `base * 2 ** (attempt - 1)`, evaluated BEFORE the
     * attempt counter is incremented, so the first failure waits one base
     * interval. This table is the documented schedule for base = 1.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public static function backoffSchedule(): array
    {
        return [
            'first failure (attempt=1)' => [1, 1],
            'second failure (attempt=2)' => [2, 2],
            'third failure (attempt=3)' => [3, 4],
            'fourth failure (attempt=4)' => [4, 8],
        ];
    }

    #[DataProvider('backoffSchedule')]
    public function test_backoff_delay_doubles_per_failure(int $attempt, int $expectedSeconds): void
    {
        set_test_config(['server-expiry.webhook_backoff_base_seconds' => 1]);

        self::assertSame($expectedSeconds, $this->read('retryDelaySeconds', $attempt));
    }

    public function test_backoff_delay_scales_with_the_configured_base(): void
    {
        set_test_config(['server-expiry.webhook_backoff_base_seconds' => 3]);

        self::assertSame([3, 6, 12, 24], [
            $this->read('retryDelaySeconds', 1),
            $this->read('retryDelaySeconds', 2),
            $this->read('retryDelaySeconds', 3),
            $this->read('retryDelaySeconds', 4),
        ]);
    }

    /**
     * The timeout is bounded to 1..60 seconds.
     *
     * Regression: an unbounded value let a single slow endpoint hold the
     * scheduler mutex for hours, because retries are processed sequentially
     * within one run.
     *
     * @return array<string, array{0: int|string, 1: int}>
     */
    public static function boundedTimeoutValues(): array
    {
        return [
            'lower bound 1 is kept' => [1, 1],
            'string lower bound is kept' => ['1', 1],
            'default 10 is kept' => [10, 10],
            'string default is kept' => ['10', 10],
            'upper bound 60 is kept' => [60, 60],
            'string upper bound is kept' => ['60', 60],
            '61 clamps to 60' => [61, 60],
            '120 clamps to 60' => [120, 60],
            '3600 clamps to 60' => [3600, 60],
            '86400 clamps to 60' => [86400, 60],
            'string 86400 clamps to 60' => ['86400', 60],
            'absurd value clamps to 60' => [PHP_INT_MAX, 60],
        ];
    }

    #[DataProvider('boundedTimeoutValues')]
    public function test_timeout_is_clamped_to_the_one_to_sixty_second_range(int|string $value, int $expected): void
    {
        set_test_config(['server-expiry.webhook_timeout_seconds' => $value]);

        self::assertSame($expected, $this->read('timeoutSeconds'));
    }

    /**
     * @param  int|string  $value
     */
    #[DataProvider('unusableValues')]
    public function test_timeout_lower_bound_is_unaffected_by_the_new_maximum($value): void
    {
        set_test_config(['server-expiry.webhook_timeout_seconds' => $value]);

        self::assertSame(
            1,
            $this->read('timeoutSeconds'),
            'The 60s maximum must not disturb the existing 1s floor'
        );
    }

    /**
     * The settings form must reject values the runtime would silently clamp,
     * so the operator sees the limit instead of a value that never applies.
     */
    public function test_settings_form_bounds_the_timeout_field_to_sixty_seconds(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../src/ServerExpiryPlugin.php');

        self::assertMatchesRegularExpression(
            "/TextInput::make\('webhook_timeout_seconds'\)(?:(?!TextInput::make).)*?->maxValue\(60\)/s",
            $source,
            'The webhook timeout field must declare maxValue(60)'
        );

        self::assertDoesNotMatchRegularExpression(
            "/TextInput::make\('webhook_timeout_seconds'\)(?:(?!TextInput::make).)*?->maxValue\(120\)/s",
            $source,
            'The previous 120-second maximum must be gone'
        );
    }

    private function read(string $method, mixed ...$arguments): int
    {
        $reflection = new ReflectionMethod(WebhookDeliveryService::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(new WebhookDeliveryService, ...$arguments);
    }
}
