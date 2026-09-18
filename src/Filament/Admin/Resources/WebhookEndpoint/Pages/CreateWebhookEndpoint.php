<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookEndpoint\Pages;

use App\Enums\TablerIcon;
use App\Traits\Filament\CanCustomizeHeaderActions;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookEndpoint\WebhookEndpointResource;

class CreateWebhookEndpoint extends CreateRecord
{
    use CanCustomizeHeaderActions;

    protected static string $resource = WebhookEndpointResource::class;

    protected static bool $canCreateAnother = false;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * Pelican's admin panel renders record-page submission as page HEADER
     * actions; the form footer is deliberately left unused by native record
     * pages (they empty getFormActions()), so stock Filament's footer-based
     * Create button never appears in this panel.
     *
     * Icons and tooltips mirror the native header-action configuration so
     * every Filament render path (labeled or icon-button) stays visible;
     * the actions remain labeled text buttons.
     *
     * @return array<Action>
     */
    protected function getDefaultHeaderActions(): array
    {
        return [
            Action::make('create')
                ->action('create')
                ->keyBindings(['mod+s'])
                ->icon(TablerIcon::Plus)
                ->tooltip('Create'),
            Action::make('cancel')
                ->color('gray')
                ->url(static::getResource()::getUrl('index'))
                ->icon(TablerIcon::ArrowBack)
                ->tooltip('Cancel'),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }
}
