<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Infrastructure\Webhooks;

use Illuminate\Support\Facades\Log;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Models\WebhookEndpoint;

/**
 * Main service for dispatching webhooks based on expiration lifecycle events.
 */
class WebhookService
{
    /**
     * Dispatch a webhook for the given expiration event.
     *
     * @param string $eventType The type of expiration event (e.g., 'server.expiry.updated')
     * @param string $serverId The ID of the server associated with the event
     * @param array $payload The data payload to send in the webhook
     * @return void
     */
    public function dispatch(string $eventType, string $serverId, array $payload): void
    {
        // Find all active endpoints subscribed to this event type
        $endpoints = WebhookEndpoint::where('active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint) => $endpoint->isSubscribedToEvent($eventType));

        if ($endpoints->isEmpty()) {
            return; // No endpoints to notify
        }

        $deliveryService = new WebhookDeliveryService();

        foreach ($endpoints as $endpoint) {
            try {
                $deliveryService->queueDelivery($endpoint, $eventType, $serverId, $payload);
            } catch (\Throwable $exception) {
                // Log the error but don't let webhook failures affect the main expiration flow
                Log::error(
                    "Webhook Service: Failed to queue webhook for endpoint {$endpoint->id}, " .
                    "event {$eventType}, server {$serverId}: {$exception->getMessage()}"
                );
                // Continue with other endpoints - webhook failures should not break expiration processing
            }
        }
    }
}