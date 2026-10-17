<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Infrastructure;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\GracePeriod;
use SquadronStrike\ServerExpiry\Infrastructure\Persistence\EloquentExpirationRepository;

use function clear_test_config;
use function set_test_config;

/**
 * Configuration boundary behavior for the expiration repository.
 *
 * The plugin settings page writes these values into `.env` as strings, so the
 * repository is the normalization boundary and must never hand the strict
 * domain value objects a string, null or negative.
 *
 * Every case here is a regression guard for a defect that was reproduced
 * against a real repository instance: malformed configuration must fail safe
 * (clamp or drop) instead of throwing out of the expiry command.
 */
final class ExpirationConfigBoundaryTest extends TestCase
{
    private EloquentExpirationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        clear_test_config();
        $this->repository = new EloquentExpirationRepository;
    }

    protected function tearDown(): void
    {
        clear_test_config();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: int|string|float|bool}>
     */
    public static function nonPositiveGraceValues(): array
    {
        return [
            'integer zero' => [0],
            'string zero' => ['0'],
            'empty string (env var present but blank)' => [''],
            'non-numeric string' => ['abc'],
            'float zero' => [0.0],
        ];
    }

    #[DataProvider('nonPositiveGraceValues')]
    public function test_non_positive_grace_hours_disable_the_grace_period(int|string|float|bool $value): void
    {
        set_test_config(['server-expiry.grace_period_hours' => $value]);

        $grace = $this->repository->getGracePeriod();

        self::assertSame(0, $grace->hours(), 'A non-positive or unparseable value must mean "no grace period"');
        self::assertFalse($grace->isEnabled());
    }

    /**
     * @return array<string, array{0: int|string, 1: int}>
     */
    public static function positiveGraceValues(): array
    {
        return [
            'int' => [48, 48],
            'env string' => ['48', 48],
            'padded string' => [' 12 ', 12],
        ];
    }

    #[DataProvider('positiveGraceValues')]
    public function test_positive_grace_hours_are_normalized_to_int(int|string $value, int $expected): void
    {
        set_test_config(['server-expiry.grace_period_hours' => $value]);

        $grace = $this->repository->getGracePeriod();

        self::assertSame($expected, $grace->hours());
        self::assertTrue($grace->isEnabled());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function disabledWarningValues(): array
    {
        return [
            'no thresholds configured' => [[]],
            'single zero' => [[0]],
            'single negative' => [[-1]],
        ];
    }

    #[DataProvider('disabledWarningValues')]
    public function test_unusable_warning_thresholds_disable_warnings_without_crashing(mixed $value): void
    {
        set_test_config(['server-expiry.warning_days_notice' => $value]);

        self::assertSame([], $this->repository->getWarningThresholds());
    }

    public function test_warning_thresholds_are_returned_sorted_ascending_as_ints(): void
    {
        set_test_config(['server-expiry.warning_days_notice' => [10, 1, 3]]);

        $days = array_map(static fn ($threshold) => $threshold->days(), $this->repository->getWarningThresholds());

        self::assertSame([1, 3, 10], $days);
    }

    /**
     * A zero/negative entry is dropped, but the usable thresholds alongside it
     * must survive — the guard must not discard the whole list.
     */
    public function test_zero_entries_are_dropped_without_discarding_real_thresholds(): void
    {
        set_test_config(['server-expiry.warning_days_notice' => [0, 7]]);

        $days = array_map(static fn ($threshold) => $threshold->days(), $this->repository->getWarningThresholds());

        self::assertSame([7], $days);
    }

    /**
     * Numeric strings are the shape the settings page writes (it stores
     * "7,3,1" in .env). The config file normalizes them with intval(), which a
     * real Laravel bootstrap always applies.
     */
    public function test_numeric_string_thresholds_are_accepted_once_normalized(): void
    {
        set_test_config(['server-expiry.warning_days_notice' => [7, 3, 1]]);

        $days = array_map(static fn ($threshold) => $threshold->days(), $this->repository->getWarningThresholds());

        self::assertSame([1, 3, 7], $days);
    }

    /**
     * Regression: a negative SERVER_EXPIRY_GRACE_HOURS previously escaped the
     * normalization boundary and crashed the expiry command/page with
     * InvalidArgumentException from GracePeriod::fromHours(). It must instead
     * disable the grace period, matching ExpirationView.php's max(0, ...).
     *
     * @return array<string, array{0: int|string}>
     */
    public static function negativeGraceValues(): array
    {
        return [
            'integer negative' => [-5],
            'string negative' => ['-5'],
            'large negative' => ['-9999'],
        ];
    }

    #[DataProvider('negativeGraceValues')]
    public function test_negative_grace_hours_clamp_to_disabled(int|string $value): void
    {
        set_test_config(['server-expiry.grace_period_hours' => $value]);

        $grace = $this->repository->getGracePeriod();

        self::assertSame(0, $grace->hours(), 'A negative grace period must clamp to 0, never throw');
        self::assertFalse($grace->isEnabled());
    }

    /**
     * The domain value object keeps its strict contract: only the config
     * boundary is forgiving, so programmatic misuse still fails loudly.
     */
    public function test_domain_grace_period_still_rejects_negative_hours(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GracePeriod::fromHours(-1);
    }

    /**
     * Regression: non-int entries previously raised TypeError from the
     * array_filter() callback declared `fn (int $day)`. Valid entries mixed
     * with malformed ones must survive; the malformed ones are dropped.
     *
     * @return array<string, array{0: mixed, 1: list<int>}>
     */
    public static function mixedThresholdValues(): array
    {
        return [
            'numeric strings' => [['7', '3', '1'], [1, 3, 7]],
            'malformed between valid' => [['7', 'abc', '3'], [3, 7]],
            'empty string entry' => [[''], []],
            'null entry' => [[null], []],
            'partially numeric' => [['7 days', '3'], [3]],
            'booleans are not thresholds' => [[true, false], []],
            'floats' => [[7.9, 3.2], [3, 7]],
            'mixed everything' => [[7, 'abc', 0, -3, '3', null, 10], [3, 7, 10]],
        ];
    }

    /**
     * @param  list<int>  $expected
     */
    #[DataProvider('mixedThresholdValues')]
    public function test_malformed_threshold_entries_are_dropped_without_throwing(mixed $value, array $expected): void
    {
        set_test_config(['server-expiry.warning_days_notice' => $value]);

        $days = array_map(static fn ($threshold) => $threshold->days(), $this->repository->getWarningThresholds());

        self::assertSame($expected, $days);
    }

    /**
     * Regression: a scalar value previously reached array_filter() and raised
     * TypeError. The comma-separated scalar is exactly what the settings form
     * writes to .env, so it must be parsed rather than rejected.
     *
     * @return array<string, array{0: string, 1: list<int>}>
     */
    public static function scalarThresholdValues(): array
    {
        return [
            'form default' => ['7,3,1', [1, 3, 7]],
            'spaces around entries' => ['7, 3, 1', [1, 3, 7]],
            'single threshold' => ['14', [14]],
            'malformed mixed in' => ['7,abc,3', [3, 7]],
            'empty string' => ['', []],
            'only separators' => [',,', []],
            'non-numeric scalar' => ['abc', []],
            'duplicates' => ['7,7,3', [3, 7]],
        ];
    }

    /**
     * @param  list<int>  $expected
     */
    #[DataProvider('scalarThresholdValues')]
    public function test_scalar_threshold_value_is_parsed_without_throwing(string $value, array $expected): void
    {
        set_test_config(['server-expiry.warning_days_notice' => $value]);

        $days = array_map(static fn ($threshold) => $threshold->days(), $this->repository->getWarningThresholds());

        self::assertSame($expected, $days);
    }

    /**
     * Regression: duplicate thresholds previously survived, so a repeated day
     * produced the same warning twice (the idempotency key includes the day).
     * Normalization must yield one entry per distinct day.
     */
    public function test_duplicate_thresholds_are_deduplicated(): void
    {
        set_test_config(['server-expiry.warning_days_notice' => [7, 7, 3, 3, 1]]);

        $days = array_map(static fn ($threshold) => $threshold->days(), $this->repository->getWarningThresholds());

        self::assertSame([1, 3, 7], $days);
    }

    /**
     * The established schedule for the documented default input must be
     * preserved exactly: 7, 3, 1 -> ascending 1, 3, 7.
     */
    public function test_default_schedule_is_preserved(): void
    {
        set_test_config(['server-expiry.warning_days_notice' => [7, 3, 1]]);

        $days = array_map(static fn ($threshold) => $threshold->days(), $this->repository->getWarningThresholds());

        self::assertSame([1, 3, 7], $days);
    }
}
