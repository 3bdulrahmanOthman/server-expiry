<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Support;

use App\Models\Server;
use Illuminate\Support\Carbon;
use SquadronStrike\ServerExpiry\Application\Services\ExpirationService;
use SquadronStrike\ServerExpiry\Domain\Expiration\Enums\ExpirationStatus;

/**
 * @deprecated Use ExpirationService instead.
 */
final class Expiry
{
    public static function isExpired(Server $server): bool
    {
        return ! blank($server->expires_at) && Carbon::parse($server->expires_at)->isPast();
    }

    public static function isExpirySuspended(Server $server): bool
    {
        // Only treat a suspension as expiration-based when the model-event hook
        // stamped it as such; manually suspended servers must never be conflated.
        return $server->isSuspended()
            && ($server->suspension_reason ?? null) === 'expiration';
    }

    public static function warningDays(): int
    {
        $days = config('server-expiry.warning_days_notice', [7, 3, 1]);

        if (! is_array($days) || count($days) === 0) {
            return 7;
        }

        return (int) max($days);
    }

    public static function statusColor(?Server $record): string
    {
        if (! $record || blank($record->expires_at)) {
            return 'gray';
        }

        if ($record->isSuspended()) {
            return 'danger';
        }

        $expirationService = app(ExpirationService::class);
        $status = $expirationService->getStatus($record->getKey());

        return match ($status) {
            ExpirationStatus::PERMANENT => 'gray',
            ExpirationStatus::ACTIVE => 'success',
            ExpirationStatus::WARNING => 'warning',
            ExpirationStatus::EXPIRED => 'danger',
            ExpirationStatus::GRACE => 'warning',
            ExpirationStatus::SUSPENDED => 'danger',
        };
    }

    public static function statusText(?Server $record): string
    {
        if (! $record || blank($record->expires_at)) {
            return trans('server-expiry::strings.status_permanent');
        }

        if ($record->isSuspended()) {
            return trans('server-expiry::strings.status_suspended');
        }

        $expirationService = app(ExpirationService::class);
        $status = $expirationService->getStatus($record->getKey());
        $expirationDate = $expirationService->getExpiration($record->getKey());

        $dateString = $expirationDate->isPermanent()
            ? ''
            : $expirationDate->getDateTime()->format('Y-m-d H:i');

        return match ($status) {
            ExpirationStatus::PERMANENT => trans('server-expiry::strings.status_permanent'),
            ExpirationStatus::ACTIVE => trans('server-expiry::strings.status_active', ['date' => $dateString]),
            ExpirationStatus::WARNING => trans('server-expiry::strings.status_expiring_soon', ['date' => $dateString]),
            ExpirationStatus::EXPIRED => trans('server-expiry::strings.status_expired', ['date' => $dateString]),
            ExpirationStatus::GRACE => trans('server-expiry::strings.status_grace', ['date' => $dateString]),
            ExpirationStatus::SUSPENDED => trans('server-expiry::strings.status_suspended'),
        };
    }

    public static function remainingText(?Server $record): string
    {
        if (! $record || blank($record->expires_at)) {
            return trans('server-expiry::strings.remaining_permanent');
        }

        $expirationService = app(ExpirationService::class);
        $remaining = $expirationService->getRemainingTime($record->getKey());

        if ($remaining === null) {
            // This should not happen if !blank($record->expires_at) and not permanent, but safe fallback
            return trans('server-expiry::strings.remaining_permanent');
        }

        // getRemainingTime() computes expiry->diff(now), so invert === 1 means
        // "now" is earlier than expiry, i.e. the server is still active.
        if ($remaining->invert === 1) {
            return trans('server-expiry::strings.remaining_in', ['time' => $remaining->format('%d days, %h hours, %i minutes, %s seconds')]);
        }

        return trans('server-expiry::strings.remaining_expired', ['time' => $remaining->format('%d days, %h hours, %i minutes, %s seconds')]);
    }

    public static function warningScheduleText(): string
    {
        $days = (array) config('server-expiry.warning_days_notice', [7, 3, 1]);

        return trans('server-expiry::strings.warning_schedule', [
            'days' => implode(', ', $days),
        ]);
    }
}
