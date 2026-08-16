<?php

namespace PelicanDev\ServerExpiry\Support;

use App\Models\Server;
use Illuminate\Support\Carbon;

final class Expiry
{
    public static function isExpired(Server $server): bool
    {
        return !blank($server->expires_at) && Carbon::parse($server->expires_at)->isPast();
    }

    public static function isExpirySuspended(Server $server): bool
    {
        return $server->isSuspended() && self::isExpired($server);
    }

    public static function warningDays(): int
    {
        $days = config('server-expiry.warning_days_notice', [7]);

        if (!is_array($days) || count($days) === 0) {
            return 7;
        }

        return (int) max($days);
    }

    public static function statusColor(?Server $record): string
    {
        if (!$record || blank($record->expires_at)) {
            return 'gray';
        }

        if ($record->isSuspended()) {
            return 'danger';
        }

        $expiresAt = Carbon::parse($record->expires_at);

        if (now()->gte($expiresAt)) {
            return 'danger';
        }

        if (now()->addDays(self::warningDays())->gte($expiresAt)) {
            return 'warning';
        }

        return 'success';
    }

    public static function remainingText(?Server $record): string
    {
        if (!$record || blank($record->expires_at)) {
            return trans('server-expiry::strings.remaining_permanent');
        }

        $expiresAt = Carbon::parse($record->expires_at);

        return $expiresAt->isPast()
            ? trans('server-expiry::strings.remaining_expired', ['time' => $expiresAt->diffForHumans()])
            : trans('server-expiry::strings.remaining_in', ['time' => $expiresAt->diffForHumans()]);
    }

    public static function warningScheduleText(): string
    {
        $days = (array) config('server-expiry.warning_days_notice', [7, 3, 1]);

        return trans('server-expiry::strings.warning_schedule', [
            'days' => implode(', ', $days),
        ]);
    }

    public static function statusText(?Server $record): string
    {
        if (!$record || blank($record->expires_at)) {
            return trans('server-expiry::strings.status_permanent');
        }

        $expiresAt = Carbon::parse($record->expires_at);

        if ($record->isSuspended()) {
            return trans('server-expiry::strings.status_suspended');
        }

        if (now()->gte($expiresAt)) {
            return trans('server-expiry::strings.status_expired', [
                'date' => $expiresAt->format('Y-m-d H:i'),
            ]);
        }

        if (now()->addDays(self::warningDays())->gte($expiresAt)) {
            return trans('server-expiry::strings.status_expiring_soon', [
                'date' => $expiresAt->format('Y-m-d H:i'),
            ]);
        }

        return trans('server-expiry::strings.status_active', [
            'date' => $expiresAt->format('Y-m-d H:i'),
        ]);
    }
}
