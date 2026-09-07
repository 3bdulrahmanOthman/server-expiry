<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Listeners;

use SquadronStrike\ServerExpiry\Domain\Events\ExpirationCreated;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\WebhookService;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\ExpirationDate;

/**
 * Handle the ExpirationCreated event by sending a webhook.
 */
class SendExpirationCreatedWebhook
{
    /**
     * Handle the event.
     */
    public function handle(ExpirationCreated $event): void
    {
        $webhookService = new WebhookService();

        $payload = [
            'server_id' => $event->serverId,
            'expiration_date' => $event->expirationDate->toString(),
            'timestamp' => $event->occurredOn->format(\DateTimeInterface::ATOM),
        ];

        $webhookService->dispatch('server.expiry.created', $event->serverId, $payload);
    }
}