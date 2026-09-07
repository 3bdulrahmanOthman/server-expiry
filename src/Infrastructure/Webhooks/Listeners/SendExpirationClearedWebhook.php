<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Listeners;

use SquadronStrike\ServerExpiry\Domain\Events\ExpirationCleared;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\WebhookService;

/**
 * Handle the ExpirationCleared event by sending a webhook.
 */
class SendExpirationClearedWebhook
{
    /**
     * Handle the event.
     */
    public function handle(ExpirationCleared $event): void
    {
        $webhookService = new WebhookService();

        $payload = [
            'server_id' => $event->serverId,
            'timestamp' => $event->occurredOn->format(\DateTimeInterface::ATOM),
        ];

        $webhookService->dispatch('server.expiry.cleared', $event->serverId, $payload);
    }
}