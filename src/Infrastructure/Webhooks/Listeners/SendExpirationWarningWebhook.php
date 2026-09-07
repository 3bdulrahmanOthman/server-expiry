<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Listeners;

use SquadronStrike\ServerExpiry\Domain\Events\ExpirationWarning;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\WebhookService;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\WarningThreshold;

/**
 * Handle the ExpirationWarning event by sending a webhook.
 */
class SendExpirationWarningWebhook
{
    /**
     * Handle the event.
     */
    public function handle(ExpirationWarning $event): void
    {
        $webhookService = new WebhookService();

        $payload = [
            'server_id' => $event->serverId,
            'warning_threshold_days' => $event->threshold->days(),
            'timestamp' => $event->occurredOn->format(\DateTimeInterface::ATOM),
        ];

        $webhookService->dispatch('server.expiry.warning', $event->serverId, $payload);
    }
}