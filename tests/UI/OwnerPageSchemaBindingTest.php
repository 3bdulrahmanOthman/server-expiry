<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Static regression guard for the owner-facing ExpirySettingsPage schema
 * binding (v2.1 Phase 1, H4).
 *
 * Pelican's ServerFormPage::form() binds the schema with
 * statePath('data') + model($this->getRecord()) — the tenant Server. The
 * page-level form() override originally REPLACED that method without chaining
 * it, so the schema had no record, Filament resolved the `?Server $record`
 * closure injections as null, and every owner-facing field degraded to the
 * v1 "Permanent" fallbacks while the admin tab showed the real state.
 */
final class OwnerPageSchemaBindingTest extends TestCase
{
    private const PAGE = __DIR__.'/../../src/Filament/Server/Pages/ExpirySettingsPage.php';

    /**
     * The form override must chain ServerFormPage::form() so the schema keeps
     * the authoritative tenant Server record binding.
     */
    public function test_form_chains_base_server_form_page_binding(): void
    {
        $source = (string) file_get_contents(self::PAGE);

        $this->assertStringContainsString(
            'parent::form($schema)',
            $source,
            'ExpirySettingsPage::form() must chain ServerFormPage::form() to bind the tenant Server record'
        );
    }

    /**
     * The binding must reach the rendered state through the record: since H5
     * the page resolves state via ExpirationView, built from
     * ExpirationService keyed on the tenant Server record — not via a
     * second data source.
     */
    public function test_owner_page_state_comes_from_the_server_record(): void
    {
        $source = (string) file_get_contents(self::PAGE);

        $this->assertStringContainsString('ExpirationService::class', $source);
        $this->assertStringContainsString('$this->getRecord()', $source);
        $this->assertStringContainsString('ExpirationView::build(', $source);
    }

    /**
     * Since H5.7 runtime verification: the view model and state icon must
     * reach the Blade view as real view data via getViewData(). Livewire
     * renders conditional template regions in separate fragment scopes, so
     * blade-local variables never reach them — only view data does.
     */
    public function test_view_model_reaches_the_view_as_view_data(): void
    {
        $source = (string) file_get_contents(self::PAGE);

        $this->assertStringContainsString('function getViewData(): array', $source);
        $this->assertMatchesRegularExpression("/'view'\s+=>\s+\\\$view/", $source);
        $this->assertMatchesRegularExpression("/'icon'\s+=>\s+\\\$view->iconSvg\(\)/", $source);
    }
}
