<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Filament\Server\Pages;

use App\Enums\TablerIcon;
use App\Filament\Server\Pages\ServerFormPage;
use BackedEnum;
use Filament\Schemas\Schema;
use SquadronStrike\ServerExpiry\Application\DTOs\ExpirationView;
use SquadronStrike\ServerExpiry\Application\Services\ExpirationService;

/**
 * Client-facing "Expiration" page for a single server. Registered on the
 * server panel through Plugin::register() -> $panel->discoverPages().
 *
 * The page presents the real expiration state from the expiration domain
 * service through a precomputed ExpirationView view model; the Blade view
 * renders it presentation-only. Clients cannot change their server's
 * expiration date (renewal is provider-only — the support_url setting
 * drives the only owner-facing CTA), so the page exposes no actions.
 */
class ExpirySettingsPage extends ServerFormPage
{
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::CalendarExclamation;

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'expiry-settings';

    protected string $view = 'server-expiry::filament.server.pages.expiry-settings';

    public function form(Schema $schema): Schema
    {
        // ServerFormPage::form() binds the schema to statePath('data') and
        // model($this->getRecord()). Skipping that chain leaves the schema
        // record-less and detaches the page from the tenant Server record.
        // The presentation itself lives in the Blade view / view model.
        $schema = parent::form($schema);

        return $schema;
    }

    /**
     * The precomputed view model consumed by the Blade view. All state comes
     * from ExpirationService keyed on the tenant Server record, plus the
     * record's own suspension state — never from a second data source.
     */
    public function expirationView(): ExpirationView
    {
        $supportUrl = trim((string) config('server-expiry.support_url', ''));

        return ExpirationView::build(
            app(ExpirationService::class),
            $this->getRecord(),
            $supportUrl !== '' ? $supportUrl : null,
        );
    }

    /**
     * The view model and state icon reach the Blade view as real view data.
     *
     * This stack's Blade does not compile the single-expression `@php(...)`
     * form, and Livewire compiles conditional regions of a component template
     * into separately-scoped fragments — variables assigned inside the
     * template (`@php ... @endphp`) never reach them, while view data is
     * extracted into every fragment. The Blade view therefore assigns no
     * local variables at all; everything arrives precomputed from here.
     *
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        $view = $this->expirationView();

        return [
            'view' => $view,
            'icon' => $view->iconSvg(),
        ];
    }

    public function getHeaderActions(): array
    {
        // Clients cannot change their server's expiration date (see README:
        // renewal is provider-only). Admin-side controls live in the admin
        // panel, so the client page intentionally exposes no header actions.
        return [];
    }

    protected function getServerUrl(int $serverId): string
    {
        return url("/server/{$serverId}");
    }

    public function getTitle(): string
    {
        return trans('server-expiry::strings.settings_title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('server-expiry::strings.settings_nav_label');
    }
}
