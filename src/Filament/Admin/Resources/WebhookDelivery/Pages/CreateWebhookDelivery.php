<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookDelivery\Pages;

use Filament\Resources\Pages\CreateRecord;
use SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookDelivery\WebhookDeliveryResource;

class CreateWebhookDelivery extends CreateRecord
{
    protected static string $resource = WebhookDeliveryResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
