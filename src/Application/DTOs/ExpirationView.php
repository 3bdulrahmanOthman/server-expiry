<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Application\DTOs;

use App\Models\Server;
use Carbon\CarbonImmutable;
use SquadronStrike\ServerExpiry\Application\Contracts\ExpirationService;
use SquadronStrike\ServerExpiry\Domain\Expiration\Enums\ExpirationStatus;

/**
 * Precomputed view model for the owner-facing Expiration page (v2.1, H5).
 *
 * Composes the authoritative expiration state from ExpirationService
 * (absorbed through the existing ExpirationInfo DTO) with the tenant
 * Server's suspension state — the exact precedence the admin tab uses —
 * so the Blade view stays presentation-only. Every field derives from
 * real backend data: service state, the Server record, and plugin config.
 * No lifecycle dates, progress or modules are fabricated here.
 */
final class ExpirationView
{
    /**
     * @param  list<array{type: string, days: int, percent: float}>  $timelineMarkers
     */
    private function __construct(
        public readonly ExpirationStatus $status,
        public readonly bool $isPermanent,
        public readonly bool $isSuspended,
        public readonly ?string $suspensionReason,
        public readonly bool $isExpirySuspension,
        public readonly ?string $expiresAtLabel,
        public readonly ?int $countdownSeconds,
        public readonly ?string $countdownKind,
        public readonly ?array $countdownParts,
        public readonly ?int $elapsedSeconds,
        public readonly ?string $graceEndsAtLabel,
        public readonly array $timelineMarkers,
        public readonly ?float $timelineNowPercent,
        public readonly bool $autoSuspendEnabled,
        public readonly array $warningThresholds,
        public readonly ?string $supportUrl,
    ) {}

    /**
     * Build the view model from the expiration domain service and the
     * tenant Server record.
     */
    public static function build(ExpirationService $service, Server $server, ?string $supportUrl): self
    {
        $serverId = $server->getKey();

        $info = new ExpirationInfo(
            serverId: $serverId,
            expirationDate: $service->getExpiration($serverId),
            status: $service->getStatus($serverId),
            remainingTime: $service->getRemainingTime($serverId),
            isInGracePeriod: $service->isInGracePeriod($serverId),
        );

        // ExpirationDate::getStatus() never returns SUSPENDED — suspension is
        // a server state. Compose it here with the same precedence the admin
        // tab uses: a suspended server presents as Suspended, with the
        // suspension reason carried through for the owner.
        $isSuspended = $server->isSuspended();
        $status = $isSuspended ? ExpirationStatus::SUSPENDED : $info->status;
        $reason = $isSuspended ? ($server->suspension_reason ?? null) : null;

        $expiresAt = $info->expirationDate->getDateTime() !== null
            ? CarbonImmutable::instance($info->expirationDate->getDateTime())
            : null;
        $expiresAtLabel = $expiresAt?->format('Y-m-d H:i');

        // The service interval is the source of truth: invert === 1 means the
        // expiry is still in the future, otherwise it has passed.
        $secondsUntilExpiry = null;
        $elapsedSeconds = null;
        if ($info->remainingTime !== null) {
            $total = ((int) $info->remainingTime->days) * 86400
                + $info->remainingTime->h * 3600
                + $info->remainingTime->i * 60
                + $info->remainingTime->s;

            if ($info->remainingTime->invert === 1) {
                $secondsUntilExpiry = $total;
            } else {
                $elapsedSeconds = $total;
            }
        }

        $graceHours = max(0, (int) config('server-expiry.grace_period_hours', 0));
        $autoSuspendEnabled = (bool) config('server-expiry.auto_suspend_enabled', true);

        $graceEndsAtLabel = null;
        $countdownSeconds = null;
        $countdownKind = null;

        if ($status === ExpirationStatus::GRACE && $autoSuspendEnabled && $expiresAt !== null) {
            // The real suspension moment: expiration + configured grace hours.
            $graceEndsAt = $expiresAt->addHours($graceHours);
            $graceEndsAtLabel = $graceEndsAt->format('Y-m-d H:i');
            $countdownKind = 'grace';
            $countdownSeconds = max(0, $graceEndsAt->getTimestamp() - CarbonImmutable::now($graceEndsAt->getTimezone())->getTimestamp());
        } elseif (in_array($status, [ExpirationStatus::ACTIVE, ExpirationStatus::WARNING], true) && $secondsUntilExpiry !== null) {
            $countdownKind = 'expiry';
            $countdownSeconds = $secondsUntilExpiry;
        }

        $thresholds = self::warningThresholds();

        [$timelineMarkers, $timelineNowPercent] = self::timeline(
            $status,
            $expiresAt,
            $thresholds,
            $graceHours,
        );

        return new self(
            status: $status,
            isPermanent: $status === ExpirationStatus::PERMANENT,
            isSuspended: $isSuspended,
            suspensionReason: $reason,
            isExpirySuspension: ($server->suspension_reason ?? null) === 'expiration',
            expiresAtLabel: $expiresAtLabel,
            countdownSeconds: $countdownSeconds,
            countdownKind: $countdownKind,
            countdownParts: self::countdownParts($countdownSeconds),
            elapsedSeconds: $elapsedSeconds,
            graceEndsAtLabel: $graceEndsAtLabel,
            timelineMarkers: $timelineMarkers,
            timelineNowPercent: $timelineNowPercent,
            autoSuspendEnabled: $autoSuspendEnabled,
            warningThresholds: $thresholds,
            supportUrl: $supportUrl,
        );
    }

    /**
     * The CSS modifier for the state-specific hero, one per lifecycle state.
     *
     * @return string lowercase state name, e.g. 'permanent', 'grace'
     */
    public function statusModifier(): string
    {
        return match ($this->status) {
            ExpirationStatus::PERMANENT => 'permanent',
            ExpirationStatus::ACTIVE => 'active',
            ExpirationStatus::WARNING => 'warning',
            ExpirationStatus::EXPIRED => 'expired',
            ExpirationStatus::GRACE => 'grace',
            ExpirationStatus::SUSPENDED => 'suspended',
        };
    }

    /**
     * The raw state icon SVG for the hero and suspension banner, delivered
     * through the page's view data and rendered in Blade via {!! !!} so icons
     * are never escaped to text (v2.0.0 regression guard). One arm per
     * lifecycle state; the default arm keeps the page alive if a state is
     * ever added upstream.
     */
    public function iconSvg(): string
    {
        return match ($this->statusModifier()) {
            'permanent' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 12c-2-2.67-4-4-6-4a4 4 0 1 0 0 8c2 0 4-1.33 6-4Zm0 0c2 2.67 4 4 6 4a4 4 0 0 0 0-8c-2 0-4 1.33-6 4Z"/></svg>',
            'active' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>',
            'warning' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 20h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
            'expired' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
            'grace' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 22h14"/><path d="M5 2h14"/><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"/><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"/></svg>',
            'suspended' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/></svg>',
            default => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>',
        };
    }

    /**
     * The state sentence shown under the hero, reusing the existing status
     * strings. For the grace period the deadline is the real suspension
     * moment (expiration + grace hours), not the expiration date itself.
     */
    public function statusSentence(): ?string
    {
        return match ($this->status) {
            ExpirationStatus::PERMANENT => trans('server-expiry::strings.status_permanent'),
            ExpirationStatus::ACTIVE => trans('server-expiry::strings.status_active', ['date' => $this->expiresAtLabel]),
            ExpirationStatus::WARNING => trans('server-expiry::strings.status_expiring_soon', ['date' => $this->expiresAtLabel]),
            ExpirationStatus::EXPIRED => trans('server-expiry::strings.status_expired', ['date' => $this->expiresAtLabel]),
            ExpirationStatus::GRACE => trans('server-expiry::strings.status_grace', ['date' => $this->graceEndsAtLabel]),
            ExpirationStatus::SUSPENDED => trans('server-expiry::strings.status_suspended'),
        };
    }

    /**
     * The "Time Remaining" card value, mirroring the admin tab semantics.
     */
    public function remainingLabel(): string
    {
        if ($this->isPermanent) {
            return trans('server-expiry::strings.remaining_permanent');
        }

        if ($this->status === ExpirationStatus::GRACE && $this->graceEndsAtLabel !== null) {
            return trans('server-expiry::strings.owner_grace_suspends_at', ['date' => $this->graceEndsAtLabel]);
        }

        if ($this->status === ExpirationStatus::GRACE) {
            return trans('server-expiry::strings.owner_grace_no_autosuspend');
        }

        if ($this->elapsedSeconds !== null) {
            return trans('server-expiry::strings.remaining_expired', ['time' => $this->humanSpan($this->elapsedSeconds)]);
        }

        if ($this->countdownSeconds !== null) {
            return trans('server-expiry::strings.remaining_in', ['time' => $this->humanSpan($this->countdownSeconds)]);
        }

        return trans('server-expiry::strings.none');
    }

    /**
     * Compact human duration, e.g. "2d 5h", "5h 12m".
     */
    public function humanSpan(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return $days.'d '.$hours.'h';
        }

        if ($hours > 0) {
            return $hours.'h '.$minutes.'m';
        }

        if ($minutes > 0) {
            return $minutes.'m';
        }

        return '<1m';
    }

    /**
     * The configured warning schedule sentence (real thresholds only).
     */
    public function warningScheduleLabel(): ?string
    {
        if ($this->isPermanent || $this->warningThresholds === []) {
            return null;
        }

        return trans('server-expiry::strings.warning_schedule', ['days' => implode(', ', $this->warningThresholds)]);
    }

    /**
     * The reason a server was suspended, as an owner-facing sentence.
     * Only 'expiration', 'manual' and 'other' reasons are presented.
     */
    public function suspensionSentence(): ?string
    {
        if (! $this->isSuspended) {
            return null;
        }

        return match ($this->suspensionReason) {
            'expiration' => trans('server-expiry::strings.owner_suspended_reason_expiration'),
            'manual' => trans('server-expiry::strings.owner_suspended_reason_manual'),
            'other' => trans('server-expiry::strings.owner_suspended_reason_other'),
            default => trans('server-expiry::strings.owner_suspended_reason_other'),
        };
    }

    /**
     * @return list<int> configured warning thresholds, sorted descending
     */
    private static function warningThresholds(): array
    {
        $days = (array) config('server-expiry.warning_days_notice', [7, 3, 1]);

        $thresholds = array_values(array_filter(
            array_map(intval(...), $days),
            fn (int $threshold): bool => $threshold > 0,
        ));

        sort($thresholds);
        rsort($thresholds);

        return $thresholds;
    }

    /**
     * Real lifecycle timeline over the configured warning window (extended by
     * the grace period where relevant): the only rendered milestones are the
     * configured warning thresholds, the expiration moment, and the real
     * auto-suspension moment. Rendered for the warning/grace/expired states.
     *
     * @param  list<int>  $thresholds
     * @return array{0: list<array{type: string, days: int, percent: float}>, 1: ?float}
     */
    private static function timeline(ExpirationStatus $status, ?CarbonImmutable $expiresAt, array $thresholds, int $graceHours): array
    {
        $renderable = in_array($status, [ExpirationStatus::WARNING, ExpirationStatus::GRACE, ExpirationStatus::EXPIRED], true);

        if (! $renderable || $expiresAt === null || $thresholds === []) {
            return [[], null];
        }

        $maxDays = max($thresholds);
        $graceSeconds = $graceHours > 0 ? $graceHours * 3600 : 0;
        $span = $maxDays * 86400 + $graceSeconds;

        if ($span <= 0) {
            return [[], null];
        }

        $markers = [];
        foreach ($thresholds as $days) {
            $markers[] = [
                'type' => 'warning',
                'days' => $days,
                'percent' => round(($maxDays - $days) * 86400 / $span * 100, 1),
            ];
        }

        $markers[] = [
            'type' => 'expiry',
            'days' => 0,
            'percent' => round($maxDays * 86400 / $span * 100, 1),
        ];

        if ($graceSeconds > 0) {
            $markers[] = [
                'type' => 'grace',
                'days' => 0,
                'percent' => 100.0,
            ];
        }

        $now = CarbonImmutable::now($expiresAt->getTimezone());
        $elapsedFromStart = $now->getTimestamp() - ($expiresAt->getTimestamp() - $maxDays * 86400);
        $nowPercent = round(min(100.0, max(0.0, $elapsedFromStart / $span * 100)), 1);

        return [$markers, $nowPercent];
    }

    /**
     * @return ?array{d: int, h: int, m: int, s: int}
     */
    private static function countdownParts(?int $seconds): ?array
    {
        if ($seconds === null) {
            return null;
        }

        return [
            'd' => intdiv($seconds, 86400),
            'h' => intdiv($seconds % 86400, 3600),
            'm' => intdiv($seconds % 3600, 60),
            's' => $seconds % 60,
        ];
    }
}
