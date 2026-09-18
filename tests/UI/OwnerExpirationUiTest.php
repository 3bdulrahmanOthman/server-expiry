<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Source-contract guards for the H5 owner Expiration UI.
 *
 * The owner page renders a precomputed ExpirationView view model through
 * the Blade view; these tests pin the presentation contract: all six real
 * lifecycle states, the permanent-only glow treatment, service-sourced
 * data, the conditional support CTA (no self-service renewal), and the
 * Stitch modules that were intentionally dropped for lack of backend
 * support.
 */
final class OwnerExpirationUiTest extends TestCase
{
    private const PAGE = __DIR__.'/../../src/Filament/Server/Pages/ExpirySettingsPage.php';

    private const VIEW_MODEL = __DIR__.'/../../src/Application/DTOs/ExpirationView.php';

    private const BLADE = __DIR__.'/../../resources/views/filament/server/pages/expiry-settings.blade.php';

    private const STRINGS = __DIR__.'/../../lang/en/strings.php';

    private const STATES = ['permanent', 'active', 'warning', 'expired', 'grace', 'suspended'];

    public function test_all_six_lifecycle_states_are_represented(): void
    {
        $viewModel = $this->source(self::VIEW_MODEL);
        $blade = $this->source(self::BLADE);

        foreach (self::STATES as $state) {
            $this->assertMatchesRegularExpression(
                '/ExpirationStatus::'.strtoupper($state).' => \''.$state.'\'/s',
                $viewModel,
                'ExpirationView::statusModifier() must map every real lifecycle state'
            );

            $this->assertStringContainsString(
                'se-expiry-hero--'.$state,
                $blade,
                'The state-specific hero treatment must cover '.$state
            );
        }
    }

    public function test_permanent_state_renders_the_permanent_treatment(): void
    {
        $blade = $this->source(self::BLADE);

        $this->assertStringContainsString('&infin;', $blade, 'Permanent must present the infinity badge');
        $this->assertStringContainsString('@if ($view->isPermanent)', $blade, 'The permanent treatment must be gated on the view model');
        $this->assertStringContainsString('se-expiry-glow-pulse', $blade, 'Permanent must carry the ambient glow animation');
    }

    public function test_permanent_glow_applies_to_no_other_state(): void
    {
        $blade = $this->source(self::BLADE);

        // The glow exists exactly once: the pulse keyframes, referenced only
        // by the --permanent hero rule (plus its reduced-motion opt-out).
        $this->assertSame(
            1,
            substr_count($blade, '@keyframes se-expiry-glow-pulse'),
            'The glow animation must be defined once'
        );

        preg_match_all(
            '/\.se-expiry-hero--([a-z]+)\s*\{[^}]*\}/s',
            $blade,
            $heroRules,
            PREG_SET_ORDER
        );

        $glowRules = array_values(array_filter(
            $heroRules,
            fn (array $rule): bool => str_contains($rule[0], 'animation: se-expiry-glow-pulse'),
        ));

        $this->assertCount(
            1,
            $glowRules,
            'Exactly one hero rule may carry the glow animation'
        );

        $this->assertSame(
            'permanent',
            $glowRules[0][1],
            'The glow animation belongs to the permanent hero rule'
        );
    }

    public function test_permanent_state_has_no_countdown_or_timeline(): void
    {
        $viewModel = $this->source(self::VIEW_MODEL);

        // Countdown targets exist only for the two real future deadlines —
        // expiry (active/warning) and the auto-suspension moment (grace) —
        // so PERMANENT (and a manually suspended server) can never produce one.
        $this->assertSame(
            2,
            substr_count($viewModel, "\$countdownKind = '"),
            'Only the expiry and grace countdown kinds may be assigned'
        );

        $this->assertMatchesRegularExpression(
            '/status === ExpirationStatus::GRACE && \$autoSuspendEnabled/s',
            $viewModel,
            'The grace countdown must be tied to the real auto-suspension configuration'
        );

        $this->assertMatchesRegularExpression(
            '/in_array\(\$status, \[ExpirationStatus::ACTIVE, ExpirationStatus::WARNING\], true\)/s',
            $viewModel,
            'The expiry countdown must be tied to the active/warning states'
        );

        // The timeline only renders for warning/grace/expired.
        foreach (['WARNING', 'GRACE', 'EXPIRED'] as $state) {
            $this->assertMatchesRegularExpression(
                '/in_array\(\$status, \[ExpirationStatus::WARNING, ExpirationStatus::GRACE, ExpirationStatus::EXPIRED\], true\)/s',
                $viewModel,
                'The lifecycle timeline is scoped to the warning-window states'
            );

            break;
        }
    }

    public function test_view_model_consumes_real_expiration_data(): void
    {
        $viewModel = $this->source(self::VIEW_MODEL);

        foreach ([
            'new ExpirationInfo(',
            '$service->getStatus(',
            '$service->getExpiration(',
            '$service->getRemainingTime(',
            '$service->isInGracePeriod(',
            '$server->isSuspended()',
            '$server->suspension_reason',
        ] as $construct) {
            $this->assertStringContainsString($construct, $viewModel, 'State must come from the expiration domain, not fabricated values');
        }
    }

    public function test_support_url_is_consumed_conditionally(): void
    {
        $page = $this->source(self::PAGE);
        $blade = $this->source(self::BLADE);

        $this->assertStringContainsString("config('server-expiry.support_url'", $page);

        $ctaPosition = (int) strpos($blade, 'href="{{ $view->supportUrl }}"');
        $gatePosition = (int) strpos($blade, '@if ($view->supportUrl !== null)');

        $this->assertNotFalse($gatePosition, 'The support CTA must be gated on the configured support_url');
        $this->assertNotFalse($ctaPosition, 'The support CTA button must exist');
        $this->assertGreaterThan($gatePosition, $ctaPosition, 'The CTA must render inside the support_url gate');

        $this->assertStringContainsString('href="{{ $view->supportUrl }}"', $blade, 'The CTA must target the configured URL (escaped)');
    }

    public function test_owner_page_has_no_renewal_actions(): void
    {
        $page = $this->source(self::PAGE);

        $this->assertMatchesRegularExpression(
            '/getHeaderActions\(\): array\s*\{.*?return \[\];/s',
            $page,
            'The owner page must keep empty header actions (renewal is provider-only)'
        );

        $this->assertStringNotContainsString('action_renew', $this->source(self::BLADE));
        $this->assertStringNotContainsString('action_extend', $this->source(self::BLADE));
        $this->assertStringNotContainsString('action_clear', $this->source(self::BLADE));
    }

    public function test_no_fabricated_stitch_modules_are_rendered(): void
    {
        $blade = $this->source(self::BLADE);

        foreach ([
            'invoice' => '/invoice/i',
            'sla' => '/\bsla\b/i',
            'hardware' => '/hardware/i',
            'archive' => '/\barchives?\b/i',
            'event log' => '/event log/i',
            'renewal package' => '/renewal package/i',
            'pricing' => '/\bpricing\b/i',
        ] as $module => $pattern) {
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $blade,
                'Stitch module without backend support must not be implemented: '.$module
            );
        }
    }

    public function test_blade_renders_the_precomputed_view_model(): void
    {
        $blade = $this->source(self::BLADE);

        // The view consumes the page-provided view model data and nothing else.
        $this->assertMatchesRegularExpression('/\$view->/', $blade, 'The view must consume the page view model data');
        $this->assertStringNotContainsString('ExpirationService::class', $blade, 'No domain service resolution in the view');
        $this->assertStringNotContainsString('config(', $blade, 'No config reads in the view — all values arrive precomputed');

        // This stack's Blade does not compile the single-expression `@php(...)`
        // form, and Livewire renders conditional template regions in separate
        // fragment scopes, so variables assigned inside the template never
        // reach them (H5.7 runtime finding). The view must not assign any
        // local variables — everything arrives as view data from the page.
        $this->assertDoesNotMatchRegularExpression('/^\s*@php\b/m', $blade, 'Blade-local PHP assignments break under Livewire fragment scoping — pass data via getViewData()');
    }

    public function test_owner_ui_translation_keys_are_defined(): void
    {
        $strings = require self::STRINGS;

        foreach ([
            'state_permanent',
            'state_active',
            'state_warning',
            'state_expired',
            'state_grace',
            'state_suspended',
            'owner_header_description',
            'owner_countdown_expiry',
            'owner_countdown_grace',
            'owner_countdown_days',
            'owner_countdown_hours',
            'owner_countdown_minutes',
            'owner_countdown_seconds',
            'owner_timeline_title',
            'owner_timeline_threshold',
            'owner_timeline_expires',
            'owner_timeline_suspension',
            'owner_timeline_now',
            'owner_grace_suspends_at',
            'owner_grace_no_autosuspend',
            'owner_suspended_reason_expiration',
            'owner_suspended_reason_manual',
            'owner_suspended_reason_other',
            'owner_support_title',
            'owner_support_description',
            'owner_support_cta',
        ] as $key) {
            $this->assertArrayHasKey($key, $strings, 'Missing translation key: '.$key);
        }
    }

    private function source(string $path): string
    {
        return (string) file_get_contents($path);
    }
}
