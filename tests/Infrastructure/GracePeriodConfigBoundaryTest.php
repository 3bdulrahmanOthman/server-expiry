<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\GracePeriod;
use SquadronStrike\ServerExpiry\Infrastructure\Persistence\EloquentExpirationRepository;

use function clear_test_config;
use function set_test_config;

/**
 * H6.1 regression guard: SERVER_EXPIRY_GRACE_HOURS reaches config() as a
 * STRING whenever it exists in the environment — which is exactly what the
 * plugin settings UI writes — and the strict domain contract
 * GracePeriod::fromHours(int) rejected it with a TypeError the moment grace
 * hours was configured (observed live: "Argument #1 ($hours) must be of type
 * int, string given, called in EloquentExpirationRepository.php on line 140").
 *
 * The repository is the normalization boundary: it must always hand the
 * domain an int. These tests run the REAL repository object against a seeded
 * config() store (the test harness has no framework; the stand-in lives in
 * tests/Support/config_test_helper.php and is guarded, so production code
 * keeps using Laravel's config()).
 */
final class GracePeriodConfigBoundaryTest extends TestCase
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
     * The exact production failure: SERVER_EXPIRY_GRACE_HOURS=48 in .env
     * reaches config() as the string "48" and must still yield a valid
     * GracePeriod instead of a TypeError.
     */
    public function test_env_string_grace_hours_is_normalized_to_int(): void
    {
        set_test_config(['server-expiry.grace_period_hours' => '48']);

        $grace = $this->repository->getGracePeriod();

        $this->assertInstanceOf(GracePeriod::class, $grace);
        $this->assertSame(48, $grace->hours());
    }

    public function test_string_zero_and_empty_normalize_to_no_grace(): void
    {
        set_test_config(['server-expiry.grace_period_hours' => '0']);
        $this->assertSame(0, $this->repository->getGracePeriod()->hours());

        set_test_config(['server-expiry.grace_period_hours' => '']);
        $this->assertSame(0, $this->repository->getGracePeriod()->hours());
    }

    /**
     * The integer path (config value set programmatically) must behave
     * exactly as before the fix.
     */
    public function test_integer_config_path_is_unchanged(): void
    {
        set_test_config(['server-expiry.grace_period_hours' => 48]);
        $this->assertSame(48, $this->repository->getGracePeriod()->hours());

        set_test_config(['server-expiry.grace_period_hours' => 0]);
        $this->assertSame(0, $this->repository->getGracePeriod()->hours());
    }

    /**
     * With no configured value, config('…', 0) resolves to the int default
     * and the grace period stays disabled — the factory-default behavior.
     */
    public function test_missing_config_falls_back_to_default_zero(): void
    {
        $grace = $this->repository->getGracePeriod();

        $this->assertSame(0, $grace->hours());
        $this->assertFalse($grace->isEnabled());
    }
}
