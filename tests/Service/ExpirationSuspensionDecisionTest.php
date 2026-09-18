<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SquadronStrike\ServerExpiry\Application\Contracts\ExpirationRepository;
use SquadronStrike\ServerExpiry\Application\Services\ExpirationService;
use SquadronStrike\ServerExpiry\Domain\Expiration\Enums\ExpirationStatus;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\ExpirationDate;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\GracePeriod;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\WarningThreshold;

/**
 * H6.1 regression guard: the lifecycle suspension DECISION that the scheduled
 * `pelican:process-server-expiration` command delegates to
 * (ExpirationService::processExpiration), exercised on the real application
 * service over the real domain value objects and a real in-memory repository.
 *
 * The boundaries here are the product contract:
 * - expired beyond the configured grace period + auto-suspend on → eligible
 * - expired but still inside the grace period → NOT eligible
 * - auto-suspend disabled → NEVER eligible
 *
 * (The exact grace boundary instant is asserted with an injectable clock at
 * the domain level in ExpirationDateTest; these cases use clear margins on
 * both sides of the boundary because the service reads the wall clock.)
 */
final class ExpirationSuspensionDecisionTest extends TestCase
{
    private const SERVER_ID = 4217;

    public function test_expired_server_beyond_grace_period_is_suspension_eligible(): void
    {
        $service = new ExpirationService($this->repository(
            expiryHoursAgo: 72,
            graceHours: 48,
            autoSuspend: true,
        ));

        $this->assertTrue(
            $service->processExpiration(self::SERVER_ID),
            'An expired server past its grace period with auto-suspend enabled must be eligible for expiration suspension'
        );
    }

    public function test_expired_server_inside_grace_period_must_not_suspend(): void
    {
        $service = new ExpirationService($this->repository(
            expiryHoursAgo: 5,
            graceHours: 48,
            autoSuspend: true,
        ));

        $this->assertFalse(
            $service->processExpiration(self::SERVER_ID),
            'A server inside its grace period must not be suspended'
        );
        $this->assertSame(
            ExpirationStatus::GRACE,
            $service->getStatus(self::SERVER_ID),
            'An expired server inside its grace period presents as Grace Period'
        );
    }

    public function test_auto_suspend_disabled_must_never_suspend(): void
    {
        $service = new ExpirationService($this->repository(
            expiryHoursAgo: 72,
            graceHours: 48,
            autoSuspend: false,
        ));

        $this->assertFalse(
            $service->processExpiration(self::SERVER_ID),
            'With SERVER_EXPIRY_AUTO_SUSPEND disabled, expired servers must never be suspended'
        );
    }

    public function test_active_and_permanent_servers_are_never_eligible(): void
    {
        $active = new ExpirationService($this->repository(
            expiryInHours: 24 * 30,
            graceHours: 48,
            autoSuspend: true,
        ));
        $permanent = new ExpirationService($this->repository(
            permanent: true,
            graceHours: 48,
            autoSuspend: true,
        ));

        $this->assertFalse($active->processExpiration(self::SERVER_ID));
        $this->assertFalse($permanent->processExpiration(self::SERVER_ID));
    }

    /**
     * A fully in-memory repository so the real service + real domain run
     * without a database. Mirrors the Eloquent implementation's contract.
     */
    private function repository(
        ?int $expiryHoursAgo = null,
        ?int $expiryInHours = null,
        bool $permanent = false,
        int $graceHours = 0,
        bool $autoSuspend = true,
        bool $notify = true,
    ): ExpirationRepository {
        if ($permanent) {
            $expiration = ExpirationDate::permanent();
        } elseif ($expiryHoursAgo !== null) {
            $expiration = ExpirationDate::fromDateTime(
                (new DateTimeImmutable('now'))->modify('-'.$expiryHoursAgo.' hours')
            );
        } else {
            $expiration = ExpirationDate::fromDateTime(
                (new DateTimeImmutable('now'))->modify('+'.$expiryInHours.' hours')
            );
        }

        return new class($expiration, $graceHours, $autoSuspend, $notify) implements ExpirationRepository
        {
            public function __construct(
                private readonly ExpirationDate $expiration,
                private readonly int $graceHours,
                private readonly bool $autoSuspend,
                private readonly bool $notify,
            ) {}

            public function getExpiration(int|string $serverId): ExpirationDate
            {
                return $this->expiration;
            }

            public function setExpiration(int|string $serverId, ExpirationDate $expirationDate): void
            {
                throw new \LogicException('not used in this test');
            }

            public function clearExpiration(int|string $serverId): void
            {
                throw new \LogicException('not used in this test');
            }

            public function getGracePeriod(): GracePeriod
            {
                return GracePeriod::fromHours($this->graceHours);
            }

            public function getWarningThresholds(): array
            {
                return [
                    WarningThreshold::fromDays(7),
                    WarningThreshold::fromDays(3),
                    WarningThreshold::fromDays(1),
                ];
            }

            public function isAutoSuspendEnabled(): bool
            {
                return $this->autoSuspend;
            }

            public function isNotifyOwnerOnSuspendEnabled(): bool
            {
                return $this->notify;
            }
        };
    }
}
