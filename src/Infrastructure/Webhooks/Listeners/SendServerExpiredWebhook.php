<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Listeners;

use SquadronStrike\ServerExpiry\Domain\Events\ServerExpired;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\WebhookService;

/**
 * Handle the ServerExpired event by sending a webhook.
 */
class SendServerExpiredWebhook
{
    /**
     * Handle the event.
     */
    public function handle(ServerExpired $event): void
    {
        $webhookService = new WebhookService();

        $payload = [
            'server_id' => $event->serverId,
            'timestamp' => $event->occurredOn->format(\DateTimeInterface::ATOM),
        ];

        $webhookService->dispatch('server.expiry.expired', $event->serverId, $payload);
    }
}