<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookDelivery\Pages;

use Filament\Schemas\Components\Tabs\Tab;
use Filament\Resources\Pages\ListRecords;
use SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookDelivery\WebhookDeliveryResource;

class ListWebhookDeliveries extends ListRecords
{
    protected static string $resource = WebhookDeliveryResource::class;

    protected function getHeaderActions(): array
    {
        // Deliveries are created by the delivery pipeline, not manually.
        return [];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'pending' => Tab::make('Pending')
                ->modifyQueryUsing(function ($query) {
                    return $query->where('status', 'pending');
                }),
            'success' => Tab::make('Success')
                ->modifyQueryUsing(function ($query) {
                    return $query->where('status', 'success');
                }),
            'failed' => Tab::make('Failed')
                ->modifyQueryUsing(function ($query) {
                    return $query->where('status', 'failed');
                }),
        ];
    }
}
