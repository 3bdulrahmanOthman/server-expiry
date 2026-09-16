<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use SquadronStrike\ServerExpiry\Application\Contracts\ExpirationRepository;
use SquadronStrike\ServerExpiry\Application\Services\ExpirationService;
use SquadronStrike\ServerExpiry\Domain\Expiration\Enums\ExpirationStatus;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\ExpirationDate;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\GracePeriod;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\WarningThreshold;

/**
 * Regression tests for the v2.0.0 TypeError defects: Pelican's Server model
 * returns an integer primary key, while the plugin's contracts historically
 * typed $serverId as string (fatal under strict_types). Every production
 * crash (Expiry helper rendering, admin actions, scheduler) passed an int.
 *
 * The read/analysis methods are exercised with a raw int ID through the real
 * service; any regression to a string-only contract fails as a TypeError
 * before an assertion is reached. Write paths dispatch Laravel events, which
 * are unavailable in this pure-PHP suite, so their contracts are verified
 * via reflection instead.
 */
final class ExpirationServiceServerIdTest extends TestCase
{
    public function test_get_status_accepts_integer_server_id(): void
    {
        // Exact path of the production crash: Expiry.php rendering statusText().
        $status = $this->service()->getStatus(6);

        $this->assertSame(ExpirationStatus::WARNING, $status);
    }

    public function test_get_status_accepts_integer_server_id_for_expired_server(): void
    {
        $service = $this->service(ExpirationDate::fromString('2020-01-01 00:00:00'));

        $this->assertSame(ExpirationStatus::EXPIRED, $service->getStatus(6));
    }

    public function test_process_warnings_accepts_integer_server_id(): void
    {
        // Exact path of the scheduler crash: ProcessServerExpirationCommand.
        $this->assertSame(7, $this->service()->processWarnings(6));
    }

    public function test_read_helpers_accept_integer_server_id(): void
    {
        $service = $this->service();

        $this->assertFalse($service->isExpired(6));
        $this->assertFalse($service->isInGracePeriod(6));
        $this->assertNotNull($service->getRemainingTime(6));
        $this->assertNotNull($service->getExpiration(6));
    }

    public function test_process_expiration_accepts_integer_server_id(): void
    {
        $this->assertFalse($this->service()->processExpiration(6));
    }

    #[DataProvider('writeMethodProvider')]
    public function test_write_contracts_declare_int_string(string $method): void
    {
        $this->assertUnionIntString(ExpirationService::class, $method);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function writeMethodProvider(): array
    {
        return [
            'setExpiration' => ['setExpiration'],
            'clearExpiration' => ['clearExpiration'],
            'extendExpiration' => ['extendExpiration'],
            'renew' => ['renew'],
        ];
    }

    #[DataProvider('repositoryMethodProvider')]
    public function test_repository_contract_declares_int_string(string $method): void
    {
        $this->assertUnionIntString(ExpirationRepository::class, $method);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function repositoryMethodProvider(): array
    {
        return [
            'getExpiration' => ['getExpiration'],
            'setExpiration' => ['setExpiration'],
            'clearExpiration' => ['clearExpiration'],
        ];
    }

    public function test_domain_events_declare_int_string_server_id(): void
    {
        $this->assertUnionIntString(
            \SquadronStrike\ServerExpiry\Domain\Events\ExpirationEvent::class,
            '__construct'
        );
    }

    private function assertUnionIntString(string $class, string $method): void
    {
        $type = (new ReflectionMethod($class, $method))->getParameters()[0]->getType();

        $names = $type instanceof ReflectionUnionType
            ? array_map(fn (ReflectionNamedType $t): string => $t->getName(), $type->getTypes())
            : [$type->getName()];

        // Reflection reports union members in a normalized order; compare as a set.
        sort($names);

        $this->assertSame(['int', 'string'], $names, "{$class}::{$method} must accept int|string server IDs");
    }

    /**
     * Build the real service over an in-memory repository. The default
     * expiration sits inside the 7-day warning window (warning threshold 7,
     * grace 48h), matching the production scheduler configuration defaults.
     */
    private function service(?ExpirationDate $expiration = null): ExpirationService
    {
        return new ExpirationService(new InMemoryExpirationRepository(
            $expiration ?? ExpirationDate::fromString((new DateTimeImmutable('+3 days'))->format('Y-m-d H:i:s'))
        ));
    }
}

/**
 * Pure-PHP repository double implementing the persistence contract.
 */
final class InMemoryExpirationRepository implements ExpirationRepository
{
    public function __construct(private readonly ExpirationDate $expiration) {}

    public function getExpiration(int|string $serverId): ExpirationDate
    {
        return $this->expiration;
    }

    public function setExpiration(int|string $serverId, ExpirationDate $expirationDate): void
    {
        // no-op for tests
    }

    public function clearExpiration(int|string $serverId): void
    {
        // no-op for tests
    }

    public function getGracePeriod(): GracePeriod
    {
        return GracePeriod::fromHours(48);
    }

    public function getWarningThresholds(): array
    {
        return [
            WarningThreshold::fromDays(1),
            WarningThreshold::fromDays(3),
            WarningThreshold::fromDays(7),
        ];
    }

    public function isAutoSuspendEnabled(): bool
    {
        return true;
    }

    public function isNotifyOwnerOnSuspendEnabled(): bool
    {
        return true;
    }
}
