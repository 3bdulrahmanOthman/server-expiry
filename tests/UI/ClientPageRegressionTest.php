<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Static regression guards for the client-facing ExpirySettingsPage and the
 * persistence repository. Filament/Laravel are not installable in this
 * pure-PHP suite, so rendering cannot be exercised here; instead these
 * tests assert the exact source constructs whose absence caused the v2.0.0
 * defects. Each assertion is tied to the failure mode it prevents.
 *
 * The H5 owner-UI rewrite moved the page presentation from form placeholders
 * into the Blade view + ExpirationView view model; the C3.x guards were
 * re-pointed at the equivalent H5 constructs (same failure mode, new layer).
 */
final class ClientPageRegressionTest extends TestCase
{
    private const PAGE = __DIR__.'/../../src/Filament/Server/Pages/ExpirySettingsPage.php';

    private const VIEW_MODEL = __DIR__.'/../../src/Application/DTOs/ExpirationView.php';

    private const BLADE = __DIR__.'/../../resources/views/filament/server/pages/expiry-settings.blade.php';

    private const REPOSITORY = __DIR__.'/../../src/Infrastructure/Persistence/EloquentExpirationRepository.php';

    /**
     * C3.2 (re-pointed for H5): a match over the lifecycle states without an
     * arm (and without a default) threw UnhandledMatchError and took the
     * whole client page down. The state mapping now lives in the
     * ExpirationView view model — it must stay exhaustive over all six
     * states, and the icon mapping must keep a default arm.
     */
    public function test_state_mapping_handles_every_state_and_default(): void
    {
        $source = $this->source(self::VIEW_MODEL);

        foreach (['PERMANENT', 'ACTIVE', 'WARNING', 'EXPIRED', 'GRACE', 'SUSPENDED'] as $state) {
            $this->assertMatchesRegularExpression(
                '/ExpirationStatus::'.$state.' =>/s',
                $source,
                'ExpirationView must map ExpirationStatus::'.$state.' — an unhandled state would crash the owner page'
            );
        }

        $this->assertStringContainsString('default =>', $this->source(self::VIEW_MODEL));
    }

    /**
     * C3.1 (re-pointed for H5): status icons are raw SVG; rendering them
     * through an escaping echo showed literal SVG source as text. The icon
     * payload is raw SVG markup in the view model, and the Blade view must
     * echo it raw ({!! !!}) and never escaped ({{ }}).
     */
    public function test_status_icon_is_rendered_as_raw_html(): void
    {
        $blade = $this->source(self::BLADE);

        $this->assertStringContainsString('<svg', $this->source(self::VIEW_MODEL), 'The icon payload must be raw SVG markup');
        $this->assertStringContainsString('{!! $icon !!}', $blade, 'Icons must be echoed raw');
        $this->assertStringNotContainsString('{{ $icon }}', $blade, 'Escaping the icon echo renders SVG source as text');
    }

    /**
     * C5.1: the owner-facing renew / set_expiration header actions let
     * clients change their own expiration date, contradicting the
     * documented provider-only policy. The H5 Blade view must not
     * reintroduce a self-service renewal path either.
     */
    public function test_client_page_exposes_no_expiration_actions(): void
    {
        $source = $this->source(self::PAGE);

        $this->assertStringNotContainsString("Action::make('renew')", $source);
        $this->assertStringNotContainsString("Action::make('set_expiration')", $source);

        $this->assertDoesNotMatchRegularExpression(
            '/wire:click|x-on:click|Action::make|action_renew/i',
            $this->source(self::BLADE),
            'The owner UI must not present any self-service expiration action'
        );
    }

    /**
     * C3.3: Bootstrap badge-* classes have no effect in Filament's
     * Tailwind-based UI.
     */
    public function test_no_bootstrap_badge_classes_remain(): void
    {
        foreach (['src', 'resources'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(__DIR__.'/../../'.$dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $this->assertStringNotContainsString(
                        'badge badge-',
                        (string) file_get_contents($file->getPathname()),
                        $file->getPathname()
                    );
                }
            }
        }
    }

    /**
     * C4: repository write paths previously returned silently when the
     * plugin schema was missing, masking incomplete installations.
     */
    public function test_repository_write_paths_fail_loudly_on_missing_schema(): void
    {
        $source = (string) file_get_contents(self::REPOSITORY);

        $this->assertSame(
            4,
            substr_count($source, "assertColumnForWrite('"),
            'All four write operations must be guarded by assertColumnForWrite()'
        );
        $this->assertStringContainsString(
            'private static array $columnCache = [];',
            $source,
            'Schema checks must be memoized per process'
        );
    }

    private function source(string $path): string
    {
        return (string) file_get_contents($path);
    }
}
