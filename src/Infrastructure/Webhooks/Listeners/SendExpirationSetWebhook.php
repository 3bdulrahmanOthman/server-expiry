<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Listeners;

use SquadronStrike\ServerExpiry\Domain\Events\ExpirationSet;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\WebhookService;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\ExpirationDate;

/**
 * Handle the ExpirationSet event by sending a webhook.
 */
class SendExpirationSetWebhook
{
    /**
     * Handle the event.
     */
    public function handle(ExpirationSet $event): void
    {
        $webhookService = new WebhookService();

        $payload = [
            'server_id' => $event->serverId,
            'expiration_date' => $event->expirationDate->isPermanent()
                ? null
                : $event->expirationDate->getDateTime()->format(\DateTimeInterface::ATOM),
            'timestamp' => $event->occurredOn->format(\DateTimeInterface::ATOM),
        ];

        // Send as updated event (we don't have create/update distinction in the event)
        $webhookService->dispatch('server.expiry.updated', $event->serverId, $payload);
    }
}