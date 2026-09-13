<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookEndpoint\Pages;

use Filament\Resources\Pages\CreateRecord;
use SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookEndpoint\WebhookEndpointResource;

class CreateWebhookEndpoint extends CreateRecord
{
    protected static string $resource = WebhookEndpointResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
