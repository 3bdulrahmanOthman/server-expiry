<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Infrastructure\Webhooks;

use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Models\WebhookEndpoint;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Models\WebhookDelivery;

/**
 * Service responsible for delivering webhooks with retry logic and exponential backoff.
 */
class WebhookDeliveryService
{
    /**
     * Maximum number of retry attempts for failed deliveries.
     */
    private const MAX_ATTEMPTS = 3;

    /**
     * Base delay in seconds for exponential backoff.
     */
    private const BASE_DELAY_SECONDS = 1;

    /**
     * Queue a webhook delivery for processing.
     *
     * @param WebhookEndpoint $endpoint The webhook endpoint configuration
     * @param string $eventType The type of event (e.g., 'server.expiry.updated')
     * @param string $serverId The ID of the server associated with the event
     * @param array $payload The data payload to send
     * @return void
     */
    public function queueDelivery(
        WebhookEndpoint $endpoint,
        string $eventType,
        string $serverId,
        array $payload
    ): void {
        // Only proceed if the endpoint is active and subscribed to this event type
        if (! $endpoint->isActive() || ! $endpoint->isSubscribedToEvent($eventType)) {
            return;
        }

        // Create delivery record
        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->id,
            'event_type' => $eventType,
            'server_id' => $serverId,
            'payload' => $payload,
            'attempt' => 1,
            'max_attempts' => self::MAX_ATTEMPTS,
            'status' => 'pending',
            'queued_at' => now(),
            'next_attempt_at' => now(), // Process immediately
        ]);

        // Attempt delivery
        $this->attemptDelivery($delivery);
    }

    /**
     * Attempt to deliver a webhook.
     *
     * @param WebhookDelivery $delivery The delivery record to process
     * @return void
     */
    public function attemptDelivery(WebhookDelivery $delivery): void
    {
        // Load the endpoint relationship
        $delivery->load('webhookEndpoint');

        $endpoint = $delivery->webhookEndpoint;

        // Double-check that endpoint is still active and subscribed
        if (! $endpoint->isActive() || ! $endpoint->isSubscribedToEvent($delivery->event_type)) {
            $delivery->update([
                'status' => 'failed',
                'error_message' => 'Webhook endpoint is inactive or unsubscribed from event',
                'processed_at' => now(),
            ]);

            return;
        }

        try {
            // Prepare the payload
            $jsonPayload = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($jsonPayload === false) {
                throw new \Exception('Failed to encode payload as JSON');
            }

            // Generate signature and timestamp
            $secretKey = $endpoint->secret_key ?? '';
            $timestamp = WebhookSignature::generateTimestamp();
            $signature = WebhookSignature::generate($jsonPayload . $timestamp, $secretKey);

            // Prepare headers
            $headers = [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Pelican-Server-Expiry/2.0',
                'X-Signature' => $signature,
                'X-Timestamp' => $timestamp,
                // Add webhook ID for identification
                'X-Webhook-ID' => strval($endpoint->id),
            ];

            // Make the HTTP request
            $response = Http::timeout(10)
                ->withHeaders($headers)
                ->post($endpoint->url, $jsonPayload);

            // Check if delivery was successful (2xx status code)
            if ($response->successful()) {
                $delivery->update([
                    'status' => 'success',
                    'http_status_code' => $response->status(),
                    'processed_at' => now(),
                ]);

                // Update endpoint success tracking
                $endpoint->increment('failure_count', -1 * min($endpoint->failure_count, PHP_INT_MAX)); // Reset failure count
                $endpoint->update(['last_success_at' => now()]);
            } else {
                throw new \Exception("HTTP {$response->status()}: {$response->body()}");
            }
        } catch (\Throwable $exception) {
            // Handle failure
            $this->handleDeliveryFailure($delivery, $exception);
        }
    }

    /**
     * Handle a failed delivery attempt.
     *
     * @param WebhookDelivery $delivery The delivery record that failed
     * @param \Throwable $exception The exception that caused the failure
     * @return void
     */
    private function handleDeliveryFailure(WebhookDelivery $delivery, \Throwable $exception): void
    {
        $attempt = $delivery->attempt;
        $maxAttempts = $delivery->max_attempts;

        // Log the failure
        Log::error(
            "Webhook delivery failed for endpoint {$delivery->webhook_endpoint_id}, " .
            "attempt {$attempt}/{$maxAttempts}: {$exception->getMessage()}"
        );

        // Check if we should retry
        if ($attempt < $maxAttempts) {
            // Calculate delay with exponential backoff
            $delaySeconds = self::BASE_DELAY_SECONDS * (2 ** ($attempt - 1));
            $nextAttemptAt = now()->addSeconds($delaySeconds);

            $delivery->update([
                'attempt' => $attempt + 1,
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'http_status_code' => $exception instanceof \Illuminate\Http\Client\ConnectionException ? null :
                                (method_exists($exception, 'getResponse') && $exception->getResponse() ?
                                 $exception->getResponse()->getStatusCode() : null),
                'next_attempt_at' => $nextAttemptAt,
            ]);
        } else {
            // Max attempts reached, mark as permanently failed
            $delivery->update([
                'attempt' => $attempt + 1,
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'http_status_code' => $exception instanceof \Illuminate\Http\Client\ConnectionException ? null :
                                (method_exists($exception, 'getResponse') && $exception->getResponse() ?
                                 $exception->getResponse()->getStatusCode() : null),
                'processed_at' => now(),
            ]);

            // Update endpoint failure tracking
            $endpoint = $delivery->webhookEndpoint;
            $endpoint->increment('failure_count');
            $endpoint->update(['last_failed_at' => now()]);
        }
    }

    /**
     * Process pending webhook deliveries that are ready for retry.
     * This method should be called by a scheduler/command.
     *
     * @return int Number of deliveries processed
     */
    public function processPendingDeliveries(): int
    {
        $pendingDeliveries = WebhookDelivery::where('status', 'failed')
            ->where('next_attempt_at', '<=', now())
            ->where('attempt', '<', DB::raw('max_attempts + 1')) // Only process if attempts remain
            ->get();

        $processedCount = 0;

        foreach ($pendingDeliveries as $delivery) {
            $this->attemptDelivery($delivery);
            $processedCount++;
        }

        return $processedCount;
    }
}