<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Listeners;

use SquadronStrike\ServerExpiry\Domain\Events\ServerSuspendedByExpiration;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\WebhookService;

/**
 * Handle the ServerSuspendedByExpiration event by sending a webhook.
 */
class SendServerSuspendedByExpirationWebhook
{
    /**
     * Handle the event.
     */
    public function handle(ServerSuspendedByExpiration $event): void
    {
        $webhookService = new WebhookService();

        $payload = [
            'server_id' => $event->serverId,
            'timestamp' => $event->occurredOn->format(\DateTimeInterface::ATOM),
        ];

        $webhookService->dispatch('server.expiry.suspended', $event->serverId, $payload);
    }
}