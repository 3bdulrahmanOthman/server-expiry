<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookDelivery\Pages;

use Filament\Resources\Pages\EditRecord;
use SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookDelivery\WebhookDeliveryResource;

class EditWebhookDelivery extends EditRecord
{
    protected static string $resource = WebhookDeliveryResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
