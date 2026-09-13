<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SquadronStrike\ServerExpiry\Domain\Expiration\Enums\ExpirationStatus;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\ExpirationDate;

final class ExpirationDateTest extends TestCase
{
    public function test_permanent_status_for_null_date(): void
    {
        $now = new DateTimeImmutable('2026-09-12 12:00:00');

        $this->assertSame(ExpirationStatus::PERMANENT, ExpirationDate::permanent()->getStatus($now, 7, 48));
        $this->assertTrue(ExpirationDate::permanent()->isPermanent());
        $this->assertFalse(ExpirationDate::permanent()->isExpired($now));
        $this->assertNull(ExpirationDate::permanent()->getRemainingTime($now));
        $this->assertNull(ExpirationDate::permanent()->getDateTime());
    }

    public function test_active_status_before_warning_window(): void
    {
        $now = new DateTimeImmutable('2026-09-12 12:00:00');
        $expiration = ExpirationDate::fromDateTime(new DateTimeImmutable('2026-09-20 12:00:00'));

        $this->assertSame(ExpirationStatus::ACTIVE, $expiration->getStatus($now, 7, 48));
        $this->assertFalse($expiration->isExpired($now));
    }

    public function test_warning_status_inside_warning_window(): void
    {
        $now = new DateTimeImmutable('2026-09-12 12:00:00');
        $expiration = ExpirationDate::fromDateTime(new DateTimeImmutable('2026-09-15 12:00:00')); // 3 days out

        $this->assertSame(ExpirationStatus::WARNING, $expiration->getStatus($now, 7, 48));
    }

    public function test_warning_window_boundary_is_inclusive_at_start(): void
    {
        $now = new DateTimeImmutable('2026-09-12 12:00:00');
        $exactlyAtStart = ExpirationDate::fromDateTime(new DateTimeImmutable('2026-09-19 12:00:00')); // 7 days out
        $oneSecondBeforeStart = ExpirationDate::fromDateTime(new DateTimeImmutable('2026-09-19 12:00:01'));

        $this->assertSame(ExpirationStatus::WARNING, $exactlyAtStart->getStatus($now, 7, 48));
        $this->assertSame(ExpirationStatus::ACTIVE, $oneSecondBeforeStart->getStatus($now, 7, 48));
    }

    public function test_zero_warning_days_disables_warning_status(): void
    {
        $now = new DateTimeImmutable('2026-09-12 12:00:00');
        $expiration = ExpirationDate::fromDateTime(new DateTimeImmutable('2026-09-13 12:00:00'));

        $this->assertSame(ExpirationStatus::ACTIVE, $expiration->getStatus($now, 0, 48));
    }

    public function test_grace_status_after_expiration_within_grace_hours(): void
    {
        $now = new DateTimeImmutable('2026-09-12 12:00:00');
        $expiration = ExpirationDate::fromDateTime(new DateTimeImmutable('2026-09-11 12:00:00')); // 24h expired

        $this->assertSame(ExpirationStatus::GRACE, $expiration->getStatus($now, 7, 48));
        $this->assertTrue($expiration->isInGracePeriod($now, 48));
    }

    public function test_grace_period_boundary_is_inclusive_at_end(): void
    {
        $now = new DateTimeImmutable('2026-09-12 12:00:00');
        $exactlyAtEnd = ExpirationDate::fromDateTime(new DateTimeImmutable('2026-09-10 12:00:00')); // exactly 48h

        $this->assertSame(ExpirationStatus::GRACE, $exactlyAtEnd->getStatus($now, 7, 48));
    }

    public function test_expired_status_after_grace_ends(): void
    {
        $now = new DateTimeImmutable('2026-09-12 12:00:00');
        $expiration = ExpirationDate::fromDateTime(new DateTimeImmutable('2026-09-10 11:59:59')); // >48h

        $this->assertSame(ExpirationStatus::EXPIRED, $expiration->getStatus($now, 7, 48));
        $this->assertFalse($expiration->isInGracePeriod($now, 48));
    }

    public function test_warning_never_applies_to_expired_server(): void
    {
        $now = new DateTimeImmutable('2026-09-12 12:00:00');
        $expiration = ExpirationDate::fromDateTime(new DateTimeImmutable('2026-09-11 12:00:00'));

        $this->assertSame(ExpirationStatus::GRACE, $expiration->getStatus($now, 7, 48));
        $this->assertNotSame(ExpirationStatus::WARNING, $expiration->getStatus($now, 7, 0));
        $this->assertSame(ExpirationStatus::EXPIRED, $expiration->getStatus($now, 7, 0));
    }

    public function test_from_string_normalizes_timezone_offset_to_app_timezone(): void
    {
        // 2026-09-20 10:00 UTC == 12:00 in UTC+2 (a common app timezone).
        // The wall-clock time must be normalized so no offset shift occurs.
        $expiration = ExpirationDate::fromString('2026-09-20T10:00:00+00:00');
        $normalized = ExpirationDate::fromString('2026-09-20 12:00:00');

        $this->assertFalse($expiration->isPermanent());
        $this->assertTrue(
            $expiration->equals($normalized),
            'An explicit UTC offset must normalize to the same instant as the local wall time'
        );
    }

    public function test_from_string_with_null_is_permanent(): void
    {
        $this->assertTrue(ExpirationDate::fromString(null)->isPermanent());
    }

    public function test_remaining_time_is_inverted_before_expiry(): void
    {
        $now = new DateTimeImmutable('2026-09-12 12:00:00');
        $expiration = ExpirationDate::fromDateTime(new DateTimeImmutable('2026-09-12 15:00:00')); // 3h left

        $remaining = $expiration->getRemainingTime($now);

        $this->assertNotNull($remaining);
        $this->assertSame(1, $remaining->invert, 'diff(expiry, now) before expiry must be invert === 1');
        $this->assertSame(3, $remaining->h);
    }

    public function test_remaining_time_is_not_inverted_after_expiry(): void
    {
        $now = new DateTimeImmutable('2026-09-12 12:00:00');
        $expiration = ExpirationDate::fromDateTime(new DateTimeImmutable('2026-09-12 09:00:00')); // 3h ago

        $remaining = $expiration->getRemainingTime($now);

        $this->assertNotNull($remaining);
        $this->assertSame(0, $remaining->invert, 'diff(expiry, now) after expiry must be invert === 0');
        $this->assertSame(3, $remaining->h);
    }

    public function test_equals_compares_instants_not_strings(): void
    {
        $a = ExpirationDate::fromString('2026-09-20 12:00:00');
        $b = ExpirationDate::fromString('2026-09-20T12:00:00');
        $c = ExpirationDate::fromString('2026-09-21 12:00:00');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
        $this->assertTrue(ExpirationDate::permanent()->equals(ExpirationDate::permanent()));
        $this->assertFalse(ExpirationDate::permanent()->equals($a));
    }
}
