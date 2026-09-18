<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Tests\UI;

use PHPUnit\Framework\TestCase;

/**
 * Guards the webhook CRUD submission actions against the Pelican panel
 * convention (v2.1, H3).
 *
 * Production rendered the stock Filament create/edit flows without any
 * submit button: Pelican's admin panel leaves the form-footer action surface
 * unused — every native record page (CreateUser, EditUser) empties
 * getFormActions() and renders Create/Save as page HEADER actions via
 * App\Traits\Filament\CanCustomizeHeaderActions. The webhook record pages
 * must follow that exact convention.
 *
 * The header actions stay labeled text buttons hardened with native
 * TablerIcon icons + tooltips: Filament's icon-button render path without
 * an icon produces an empty (visually invisible) element carrying only the
 * label as its title attribute — the signature observed on production.
 */
final class WebhookCrudActionsTest extends TestCase
{
    private const ENDPOINT_LIST = __DIR__.'/../../src/Filament/Admin/Resources/WebhookEndpoint/Pages/ListWebhookEndpoints.php';

    private const ENDPOINT_CREATE = __DIR__.'/../../src/Filament/Admin/Resources/WebhookEndpoint/Pages/CreateWebhookEndpoint.php';

    private const ENDPOINT_EDIT = __DIR__.'/../../src/Filament/Admin/Resources/WebhookEndpoint/Pages/EditWebhookEndpoint.php';

    private const DELIVERY_CREATE = __DIR__.'/../../src/Filament/Admin/Resources/WebhookDelivery/Pages/CreateWebhookDelivery.php';

    private const DELIVERY_EDIT = __DIR__.'/../../src/Filament/Admin/Resources/WebhookDelivery/Pages/EditWebhookDelivery.php';

    private const EMPTY_FOOTER = '/protected function getFormActions\(\): array\s*\{\s*return \[\];\s*\}/s';

    public function test_endpoint_list_create_action_targets_the_create_page(): void
    {
        $source = $this->source(self::ENDPOINT_LIST);

        $this->assertStringContainsString('CreateAction::make()', $source);
        $this->assertStringContainsString(
            "WebhookEndpointResource::getUrl('create')",
            $source,
            'The Plus button must navigate to the create page'
        );
    }

    public function test_record_pages_use_the_pelican_header_action_trait(): void
    {
        foreach ($this->recordPages() as $file) {
            $source = $this->source($file);

            $this->assertStringContainsString('use CanCustomizeHeaderActions;', $source, $file);
            $this->assertStringContainsString('protected function getDefaultHeaderActions(): array', $source, $file);
        }
    }

    public function test_create_pages_render_a_header_create_action(): void
    {
        foreach ([self::ENDPOINT_CREATE, self::DELIVERY_CREATE] as $file) {
            $source = $this->source($file);

            $this->assertStringContainsString("Action::make('create')", $source, $file);
            $this->assertStringContainsString("->action('create')", $source, $file);
            $this->assertStringContainsString('protected static bool $canCreateAnother = false;', $source, $file);
        }
    }

    public function test_edit_pages_render_a_header_save_action(): void
    {
        foreach ([self::ENDPOINT_EDIT, self::DELIVERY_EDIT] as $file) {
            $source = $this->source($file);

            $this->assertStringContainsString("Action::make('save')", $source, $file);
            $this->assertStringContainsString("->action('save')", $source, $file);
        }
    }

    public function test_record_pages_provide_a_header_cancel_action(): void
    {
        foreach ($this->recordPages() as $file) {
            $source = $this->source($file);

            $this->assertStringContainsString("Action::make('cancel')", $source, $file);
            $this->assertStringContainsString("getUrl('index')", $source, $file);
        }
    }

    public function test_header_actions_carry_native_icons_and_tooltips(): void
    {
        $expectations = [
            self::ENDPOINT_CREATE => [
                ['create', 'Plus', 'Create'],
                ['cancel', 'ArrowBack', 'Cancel'],
            ],
            self::DELIVERY_CREATE => [
                ['create', 'Plus', 'Create'],
                ['cancel', 'ArrowBack', 'Cancel'],
            ],
            self::ENDPOINT_EDIT => [
                ['save', 'DeviceFloppy', 'Save'],
                ['cancel', 'ArrowBack', 'Cancel'],
            ],
            self::DELIVERY_EDIT => [
                ['save', 'DeviceFloppy', 'Save'],
                ['cancel', 'ArrowBack', 'Cancel'],
            ],
        ];

        foreach ($expectations as $file => $actions) {
            $source = $this->source($file);

            $this->assertStringContainsString('use App\Enums\TablerIcon;', $source, $file);

            foreach ($actions as [$name, $icon, $tooltip]) {
                $this->assertStringContainsString("Action::make('{$name}')", $source, $file);
                $this->assertStringContainsString("->icon(TablerIcon::{$icon})", $source, $file);
                $this->assertStringContainsString("->tooltip('{$tooltip}')", $source, $file);
            }
        }
    }

    public function test_header_actions_stay_labeled_text_buttons(): void
    {
        // v2.1 keeps these actions as labeled buttons. The icon-only render
        // paths (hiddenLabel/iconButton/labeledFrom) are exactly what made
        // the production header buttons invisible.
        foreach ($this->recordPages() as $file) {
            $source = $this->source($file);

            $this->assertStringNotContainsString('->hiddenLabel(', $source, $file);
            $this->assertStringNotContainsString('->iconButton(', $source, $file);
            $this->assertStringNotContainsString('->labeledFrom(', $source, $file);
        }
    }

    public function test_record_pages_empty_the_form_footer(): void
    {
        foreach ($this->recordPages() as $file) {
            $this->assertMatchesRegularExpression(
                self::EMPTY_FOOTER,
                $this->source($file),
                $file.' must leave getFormActions() empty — the Pelican panel does not render form footers'
            );
        }
    }

    public function test_create_pages_keep_the_post_creation_redirect(): void
    {
        foreach ([self::ENDPOINT_CREATE, self::DELIVERY_CREATE] as $file) {
            $source = $this->source($file);

            $this->assertStringContainsString("getResource()::getUrl('index')", $source, $file);
        }
    }

    /**
     * @return array<string>
     */
    private function recordPages(): array
    {
        return [self::ENDPOINT_CREATE, self::ENDPOINT_EDIT, self::DELIVERY_CREATE, self::DELIVERY_EDIT];
    }

    private function source(string $path): string
    {
        return (string) file_get_contents($path);
    }
}
