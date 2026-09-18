<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookEndpoint\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookEndpoint\WebhookEndpointResource;

class ListWebhookEndpoints extends ListRecords
{
    protected static string $resource = WebhookEndpointResource::class;

    protected function getHeaderActions(): array
    {
        // Route the Plus button to the dedicated create page. Filament v5's
        // CreateAction otherwise opens a slide-over modal whose footer submit
        // proved unreliable in production panel builds; the page flow pins its
        // own Create/Cancel actions instead.
        return [
            CreateAction::make()
                ->url(WebhookEndpointResource::getUrl('create')),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'active' => Tab::make('Active')
                ->modifyQueryUsing(function ($query) {
                    return $query->where('active', true);
                }),
            'inactive' => Tab::make('Inactive')
                ->modifyQueryUsing(function ($query) {
                    return $query->where('active', false);
                }),
        ];
    }
}
