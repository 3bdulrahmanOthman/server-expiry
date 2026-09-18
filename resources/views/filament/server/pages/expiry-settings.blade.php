{{-- Owner Expiration page (v2.1, H5). Presentation only: every value comes
     from the precomputed ExpirationView view model produced by
     ExpirySettingsPage::expirationView() from the expiration domain service
     and the tenant Server record. No business logic lives here. --}}

{{-- NOTE: `$view` and `$icon` arrive as real view data from
     ExpirySettingsPage::getViewData() — this stack's Blade does not compile
     the single-expression `@php(...)` form, and Livewire compiles conditional
     regions of a component template into separately-scoped fragments, so
     variables assigned inside the template never reach them. The view must
     not assign any local variables (`@php`) at all. --}}

@once
    <style>
        .se-expiry-root {
            --se-ink: #0f172a;
            --se-muted: #64748b;
            --se-card: #ffffff;
            --se-card-border: rgba(15, 23, 42, 0.08);
            --se-soft: rgba(248, 250, 252, 0.8);
            --se-track: rgba(100, 116, 139, 0.22);
            --se-permanent: #10b981;
            --se-active: #10b981;
            --se-warning: #f59e0b;
            --se-grace: #f97316;
            --se-expired: #ef4444;
            --se-suspended: #dc2626;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            max-width: 56rem;
            margin: 0 auto;
            color: var(--se-ink);
        }

        .dark .se-expiry-root {
            --se-ink: #e2e8f0;
            --se-muted: #94a3b8;
            --se-card: rgba(17, 24, 39, 0.72);
            --se-card-border: rgba(148, 163, 184, 0.14);
            --se-soft: rgba(30, 41, 59, 0.5);
            --se-track: rgba(148, 163, 184, 0.25);
        }

        .se-expiry-header {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .se-expiry-title {
            margin: 0;
            font-size: 1.375rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .se-expiry-subtitle {
            margin: 0;
            color: var(--se-muted);
            font-size: 0.875rem;
        }

        .se-expiry-hero {
            position: relative;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1.25rem 1.5rem;
            background: var(--se-card);
            border: 1px solid var(--se-card-border);
            border-radius: 0.875rem;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }

        .se-expiry-hero-icon {
            flex: none;
            display: grid;
            place-items: center;
            width: 2.75rem;
            height: 2.75rem;
            border-radius: 0.75rem;
            background: color-mix(in srgb, var(--se-state, var(--se-muted)) 12%, transparent);
            color: var(--se-state, var(--se-muted));
        }

        .se-expiry-hero-icon svg {
            width: 1.5rem;
            height: 1.5rem;
        }

        .se-expiry-hero--permanent { --se-state: var(--se-permanent); }
        .se-expiry-hero--active { --se-state: var(--se-active); }
        .se-expiry-hero--warning { --se-state: var(--se-warning); }
        .se-expiry-hero--grace { --se-state: var(--se-grace); }
        .se-expiry-hero--expired { --se-state: var(--se-expired); }
        .se-expiry-hero--suspended { --se-state: var(--se-suspended); }

        /* Subtle green ambient/glow treatment — PERMANENT state only. */
        .se-expiry-hero--permanent {
            border-color: rgba(16, 185, 129, 0.35);
            background:
                radial-gradient(120% 140% at 50% -20%, rgba(16, 185, 129, 0.16), transparent 60%),
                var(--se-card);
            animation: se-expiry-glow-pulse 6s ease-in-out infinite;
        }

        @keyframes se-expiry-glow-pulse {
            0%, 100% { box-shadow: 0 0 60px -12px rgba(16, 185, 129, 0.40); }
            50% { box-shadow: 0 0 70px -10px rgba(16, 185, 129, 0.55); }
        }

        @media (prefers-reduced-motion: reduce) {
            .se-expiry-hero--permanent { animation: none; }
        }

        .se-expiry-hero-body {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            min-width: 0;
        }

        .se-expiry-state {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--se-state);
        }

        .se-expiry-state-dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 9999px;
            background: var(--se-state);
        }

        .se-expiry-sentence {
            margin: 0;
            color: var(--se-muted);
            font-size: 0.9rem;
        }

        .se-expiry-infinity {
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .se-expiry-countdown {
            display: flex;
            align-items: stretch;
            gap: 0.75rem;
            padding: 1.125rem 1.5rem;
            background: var(--se-card);
            border: 1px solid var(--se-card-border);
            border-radius: 0.875rem;
        }

        .se-expiry-countdown-label {
            display: flex;
            align-items: center;
            color: var(--se-muted);
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding-inline-end: 0.75rem;
            border-inline-end: 1px solid var(--se-card-border);
        }

        .se-expiry-countdown-cells {
            display: flex;
            gap: 1.25rem;
        }

        .se-expiry-count {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 2.5rem;
        }

        .se-expiry-count-value {
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1.2;
            font-variant-numeric: tabular-nums;
        }

        .se-expiry-count-label {
            color: var(--se-muted);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .se-expiry-timeline {
            position: relative;
            padding: 0.5rem 1.5rem 1.75rem;
            background: var(--se-card);
            border: 1px solid var(--se-card-border);
            border-radius: 0.875rem;
        }

        .se-expiry-timeline-title {
            margin: 0 0 1rem;
            color: var(--se-muted);
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .se-expiry-timeline-track {
            position: relative;
            height: 0.375rem;
            border-radius: 9999px;
            background: var(--se-track);
        }

        .se-expiry-timeline-marker {
            position: absolute;
            top: 50%;
            transform: translate(-50%, -50%);
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .se-expiry-timeline-dot {
            width: 0.625rem;
            height: 0.625rem;
            border-radius: 9999px;
            background: var(--se-card);
            border: 2px solid var(--se-muted);
        }

        .se-expiry-timeline-marker--expiry .se-expiry-timeline-dot,
        .se-expiry-timeline-marker--grace .se-expiry-timeline-dot {
            border-color: var(--se-grace);
        }

        .se-expiry-timeline-marker--warning .se-expiry-timeline-dot {
            border-color: var(--se-warning);
        }

        .se-expiry-timeline-label {
            position: absolute;
            top: 1rem;
            white-space: nowrap;
            color: var(--se-muted);
            font-size: 0.7rem;
        }

        .se-expiry-timeline-now {
            position: absolute;
            top: -0.375rem;
            bottom: -0.375rem;
            width: 2px;
            border-radius: 9999px;
            background: var(--se-ink);
            transform: translateX(-50%);
        }

        .se-expiry-timeline-now-label {
            position: absolute;
            top: 0.85rem;
            transform: translateX(-50%);
            color: var(--se-ink);
            font-size: 0.7rem;
            font-weight: 700;
        }

        .se-expiry-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
            gap: 0.75rem;
        }

        .se-expiry-card {
            padding: 1rem 1.25rem;
            background: var(--se-card);
            border: 1px solid var(--se-card-border);
            border-radius: 0.875rem;
        }

        .se-expiry-card-label {
            margin: 0 0 0.375rem;
            color: var(--se-muted);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .se-expiry-card-value {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 600;
        }

        .se-expiry-banner {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 1rem 1.25rem;
            border: 1px solid color-mix(in srgb, var(--se-suspended) 35%, transparent);
            border-radius: 0.875rem;
            background: color-mix(in srgb, var(--se-suspended) 8%, var(--se-card));
            color: var(--se-suspended);
        }

        .se-expiry-banner svg {
            flex: none;
            width: 1.25rem;
            height: 1.25rem;
            margin-top: 0.125rem;
        }

        .se-expiry-banner-body {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .se-expiry-banner-title {
            margin: 0;
            font-weight: 700;
        }

        .se-expiry-banner-text {
            margin: 0;
            color: var(--se-muted);
            font-size: 0.9rem;
        }

        .se-expiry-support {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 1.125rem 1.5rem;
            background: var(--se-soft);
            border: 1px solid var(--se-card-border);
            border-radius: 0.875rem;
        }

        .se-expiry-support-body {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            min-width: 0;
        }

        .se-expiry-support-title {
            margin: 0;
            font-weight: 700;
        }

        .se-expiry-support-text {
            margin: 0;
            color: var(--se-muted);
            font-size: 0.9rem;
        }

        .se-expiry-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            background: #059669;
            color: #ffffff;
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            transition: background-color 0.15s ease;
        }

        .se-expiry-btn:hover {
            background: #047857;
            color: #ffffff;
        }

        .se-expiry-btn:focus-visible {
            outline: 2px solid #059669;
            outline-offset: 2px;
        }

        @media (max-width: 640px) {
            .se-expiry-hero,
            .se-expiry-countdown,
            .se-expiry-support {
                flex-direction: column;
            }

            .se-expiry-countdown-label {
                border-inline-end: none;
                padding-inline-end: 0;
            }

            .se-expiry-timeline-label {
                font-size: 0.62rem;
            }
        }
    </style>
@endonce

<div class="se-expiry-root">
    <header class="se-expiry-header">
        <h1 class="se-expiry-title">{{ trans('server-expiry::strings.settings_title') }}</h1>
        <p class="se-expiry-subtitle">{{ trans('server-expiry::strings.owner_header_description') }}</p>
    </header>

    <section
        class="se-expiry-hero se-expiry-hero--{{ $view->statusModifier() }}"
        aria-live="polite"
    >
        <span class="se-expiry-hero-icon">{!! $icon !!}</span>

        <div class="se-expiry-hero-body">
            <span class="se-expiry-state">
                <span class="se-expiry-state-dot"></span>

                @if ($view->isPermanent)
                    <span class="se-expiry-infinity">&infin;</span>
                @endif

                {{ trans('server-expiry::strings.state_'.$view->statusModifier()) }}
            </span>

            @if ($view->statusSentence() !== null)
                <p class="se-expiry-sentence">{{ $view->statusSentence() }}</p>
            @endif
        </div>
    </section>

    @if ($view->countdownSeconds !== null && $view->countdownParts !== null)
        <section
            class="se-expiry-countdown"
            x-data="{
                total: {{ $view->countdownSeconds }},
                d: {{ $view->countdownParts['d'] }},
                h: {{ $view->countdownParts['h'] }},
                m: {{ $view->countdownParts['m'] }},
                sec: {{ $view->countdownParts['s'] }},
                timer: null,
                split() {
                    this.d = Math.floor(this.total / 86400);
                    this.h = Math.floor(this.total % 86400 / 3600);
                    this.m = Math.floor(this.total % 3600 / 60);
                    this.sec = this.total % 60;
                },
            }"
            x-init="split(); timer = setInterval(() => { if (total > 0) { total--; split(); } }, 1000)"
            x-destroy="clearInterval(timer)"
            role="timer"
            aria-label="{{ trans('server-expiry::strings.remaining_label') }}"
        >
            <span class="se-expiry-countdown-label">
                {{ trans($view->countdownKind === 'grace' ? 'server-expiry::strings.owner_countdown_grace' : 'server-expiry::strings.owner_countdown_expiry') }}
            </span>

            <div class="se-expiry-countdown-cells">
                <span class="se-expiry-count">
                    <span class="se-expiry-count-value" x-text="d">{{ $view->countdownParts['d'] }}</span>
                    <span class="se-expiry-count-label">{{ trans('server-expiry::strings.owner_countdown_days') }}</span>
                </span>
                <span class="se-expiry-count">
                    <span class="se-expiry-count-value" x-text="h">{{ $view->countdownParts['h'] }}</span>
                    <span class="se-expiry-count-label">{{ trans('server-expiry::strings.owner_countdown_hours') }}</span>
                </span>
                <span class="se-expiry-count">
                    <span class="se-expiry-count-value" x-text="m">{{ $view->countdownParts['m'] }}</span>
                    <span class="se-expiry-count-label">{{ trans('server-expiry::strings.owner_countdown_minutes') }}</span>
                </span>
                <span class="se-expiry-count">
                    <span class="se-expiry-count-value" x-text="(sec < 10 ? '0' : '') + sec">{{ $view->countdownParts['s'] }}</span>
                    <span class="se-expiry-count-label">{{ trans('server-expiry::strings.owner_countdown_seconds') }}</span>
                </span>
            </div>
        </section>
    @endif

    @if ($view->timelineNowPercent !== null)
        <section class="se-expiry-timeline">
            <h2 class="se-expiry-timeline-title">{{ trans('server-expiry::strings.owner_timeline_title') }}</h2>

            <div class="se-expiry-timeline-track">
                @foreach ($view->timelineMarkers as $marker)
                    <span
                        class="se-expiry-timeline-marker se-expiry-timeline-marker--{{ $marker['type'] }}"
                        style="left: {{ $marker['percent'] }}%;"
                    >
                        <span class="se-expiry-timeline-dot"></span>

                        <span class="se-expiry-timeline-label">
                            @if ($marker['type'] === 'warning')
                                {{ trans('server-expiry::strings.owner_timeline_threshold', ['days' => $marker['days']]) }}
                            @elseif ($marker['type'] === 'expiry')
                                {{ trans('server-expiry::strings.owner_timeline_expires') }}
                            @else
                                {{ trans('server-expiry::strings.owner_timeline_suspension') }}
                            @endif
                        </span>
                    </span>
                @endforeach

                <span class="se-expiry-timeline-now" style="left: {{ $view->timelineNowPercent }}%;">
                    <span class="se-expiry-timeline-now-label">{{ trans('server-expiry::strings.owner_timeline_now') }}</span>
                </span>
            </div>
        </section>
    @endif

    <section class="se-expiry-cards">
        <div class="se-expiry-card">
            <p class="se-expiry-card-label">{{ trans('server-expiry::strings.status_label') }}</p>

            <p class="se-expiry-card-value">
                {{ trans('server-expiry::strings.state_'.$view->statusModifier()) }}
            </p>
        </div>

        <div class="se-expiry-card">
            <p class="se-expiry-card-label">{{ trans('server-expiry::strings.field_label') }}</p>

            <p class="se-expiry-card-value">
                {{ $view->expiresAtLabel ?? trans('server-expiry::strings.column_permanent') }}
            </p>
        </div>

        <div class="se-expiry-card">
            <p class="se-expiry-card-label">{{ trans('server-expiry::strings.remaining_label') }}</p>

            <p class="se-expiry-card-value">{{ $view->remainingLabel() }}</p>
        </div>

        @if ($view->warningScheduleLabel() !== null)
            <div class="se-expiry-card">
                <p class="se-expiry-card-label">{{ trans('server-expiry::strings.warning_label') }}</p>

                <p class="se-expiry-card-value">{{ $view->warningScheduleLabel() }}</p>
            </div>
        @endif
    </section>

    @if ($view->isSuspended)
        <section class="se-expiry-banner">
            {!! $icon !!}

            <div class="se-expiry-banner-body">
                <p class="se-expiry-banner-title">{{ trans('server-expiry::strings.state_suspended') }}</p>

                @if ($view->suspensionSentence() !== null)
                    <p class="se-expiry-banner-text">{{ $view->suspensionSentence() }}</p>
                @endif
            </div>
        </section>
    @endif

    {{-- The only owner-facing CTA: provider support. Hidden entirely when the
         support_url setting is empty. No self-service renewal exists. --}}
    @if ($view->supportUrl !== null)
        <section class="se-expiry-support">
            <div class="se-expiry-support-body">
                <p class="se-expiry-support-title">{{ trans('server-expiry::strings.owner_support_title') }}</p>
                <p class="se-expiry-support-text">{{ trans('server-expiry::strings.owner_support_description') }}</p>
            </div>

            <a
                class="se-expiry-btn"
                href="{{ $view->supportUrl }}"
                target="_blank"
                rel="noopener noreferrer"
            >
                {{ trans('server-expiry::strings.owner_support_cta') }}
            </a>
        </section>
    @endif
</div>
