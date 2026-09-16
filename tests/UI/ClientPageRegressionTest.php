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
 */
final class ClientPageRegressionTest extends TestCase
{
    private const PAGE = __DIR__.'/../../src/Filament/Server/Pages/ExpirySettingsPage.php';

    private const REPOSITORY = __DIR__.'/../../src/Infrastructure/Persistence/EloquentExpirationRepository.php';

    /**
     * C3.2: statusColor() returns 'danger' for expired/suspended servers;
     * a match without that arm (and without a default) threw
     * UnhandledMatchError and took the whole client page down.
     */
    public function test_status_icon_match_handles_danger_and_default(): void
    {
        $source = $this->pageSource();

        $this->assertStringContainsString("'danger' =>", $source);
        $this->assertStringContainsString('default =>', $source);
    }

    /**
     * C3.1: the status icon placeholder returned raw SVG that rendered as
     * escaped text until ->html() was added.
     */
    public function test_status_icon_placeholder_is_rendered_as_html(): void
    {
        $source = $this->pageSource();

        $this->assertStringContainsString("Placeholder::make('status_icon')", $source);
        $this->assertMatchesRegularExpression(
            '/status_icon.*->html\(\)/s',
            $source,
            'status_icon placeholder must render its static SVG via ->html()'
        );
    }

    /**
     * C5.1: the owner-facing renew / set_expiration header actions let
     * clients change their own expiration date, contradicting the
     * documented provider-only policy.
     */
    public function test_client_page_exposes_no_expiration_actions(): void
    {
        $source = $this->pageSource();

        $this->assertStringNotContainsString("Action::make('renew')", $source);
        $this->assertStringNotContainsString("Action::make('set_expiration')", $source);
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

    private function pageSource(): string
    {
        return (string) file_get_contents(self::PAGE);
    }
}
