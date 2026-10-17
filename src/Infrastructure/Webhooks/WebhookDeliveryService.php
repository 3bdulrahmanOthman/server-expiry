<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Infrastructure\Webhooks;

use App\Models\Server;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Models\WebhookDelivery;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Models\WebhookEndpoint;
use SquadronStrike\ServerExpiry\Support\WebhookDeliveryClaim;

/**
 * Service responsible for delivering webhooks with retry logic and exponential backoff.
 */
class WebhookDeliveryService
{
    /**
     * Recorded when a delivery's last permitted attempt was consumed by a worker
     * that then died, so no HTTP result was ever written. The receiver's last
     * known HTTP status stays in `http_status_code`; this message explains why the
     * delivery is nevertheless terminal.
     */
    private const ABANDONED_ERROR_MESSAGE = 'Delivery abandoned: the worker holding the final attempt stopped responding';

    /**
     * Maximum HTTP sends per delivery, counting the initial send
     * (config: webhook_max_attempts).
     */
    private function maxAttempts(): int
    {
        return max(1, (int) config('server-expiry.webhook_max_attempts', 3));
    }

    /**
     * HTTP timeout per delivery request, in seconds (config: webhook_timeout_seconds).
     *
     * Clamped to 1..60. The upper bound matters because retries are processed
     * sequentially inside a single scheduler run that holds a `withoutOverlapping`
     * mutex (see ServerExpiryServiceProvider), so one slow endpoint's timeout is
     * paid by every remaining delivery in that pass. An unbounded value let a
     * single hung endpoint block the retry pipeline for hours.
     */
    private function timeoutSeconds(): int
    {
        return min(60, max(1, (int) config('server-expiry.webhook_timeout_seconds', 10)));
    }

    /**
     * Base delay for the exponential retry backoff, in seconds
     * (config: webhook_backoff_base_seconds).
     */
    private function backoffBaseSeconds(): int
    {
        return max(1, (int) config('server-expiry.webhook_backoff_base_seconds', 1));
    }

    /**
     * Delay before the retry that follows the given failed attempt, in seconds.
     *
     * Evaluated with the attempt counter BEFORE it is incremented, so the first
     * failure waits one base interval and each subsequent failure doubles it:
     * base * 2 ** (attempt - 1).
     */
    private function retryDelaySeconds(int $attempt): int
    {
        return WebhookDeliveryClaim::retryDelaySeconds($attempt, $this->backoffBaseSeconds());
    }

    /**
     * Atomically take ownership of a delivery row before it is sent.
     *
     * The guarded UPDATE is the compare-and-set: the WHERE clause re-checks
     * status, retry schedule, current claim and attempt budget in one statement,
     * so exactly one of two racing workers can match the row. A loser affects
     * zero rows and must skip the delivery rather than send it.
     *
     * The attempt budget is consumed here, not after the response, so a crash
     * mid-request cannot grant an unbounded number of retries.
     *
     * @return string|null The claim token, or null when the row was not claimed.
     */
    private function claim(WebhookDelivery $delivery): ?string
    {
        $token = WebhookDeliveryClaim::newToken();
        $now = now();
        $staleBefore = $now->copy()->subSeconds(WebhookDeliveryClaim::LEASE_SECONDS);

        $claimed = WebhookDelivery::where('id', $delivery->id)
            // A success is finished; only due retries and pending rows are candidates.
            ->whereIn('status', WebhookDeliveryClaim::CLAIMABLE_STATUSES)
            // The attempt budget is consumed on claim, so it can never be exceeded
            // even if the worker dies mid-request. `attempt` counts attempts already
            // consumed, so `attempt < max_attempts` means "sends remain" and the
            // configured maximum is the exact number of HTTP sends, initial included.
            ->whereColumn('attempt', '<', 'max_attempts')
            ->where(function ($query) use ($now, $staleBefore) {
                $query
                    // A retry that is due and not currently held by a live lease.
                    ->where(function ($query) use ($now, $staleBefore) {
                        $query->where('status', 'failed')
                            ->where(function ($query) use ($now) {
                                $query->whereNull('next_attempt_at')
                                    ->orWhere('next_attempt_at', '<=', $now);
                            })
                            ->where(function ($query) use ($staleBefore) {
                                $query->whereNull('claimed_at')
                                    ->orWhere('claimed_at', '<=', $staleBefore);
                            });
                    })
                    // A lease that ran out (the worker crashed mid-request).
                    ->orWhere(function ($query) use ($staleBefore) {
                        $query->where('status', 'pending')
                            ->whereNotNull('claimed_at')
                            ->where('claimed_at', '<=', $staleBefore);
                    })
                    // A pending row that was never claimed: the process died
                    // between creating it and taking the claim, so it is only
                    // recoverable once it is provably abandoned.
                    ->orWhere(function ($query) use ($staleBefore) {
                        $query->where('status', 'pending')
                            ->whereNull('claimed_at')
                            ->where('next_attempt_at', '<=', $staleBefore);
                    });
            })
            ->update([
                'claim_token' => $token,
                'claimed_at' => $now,
                // Consume the attempt now so a crash mid-request cannot grant an
                // unbounded number of retries.
                //
                // No attempt re-basing is applied to rows written before this
                // meaning changed. Their old value (1 = "currently on attempt 1")
                // already reads as "one attempt consumed", which is exactly right
                // for a row whose send was in flight. A row the old code stranded
                // before its first send is treated as having spent that attempt:
                // conservative (it may send one fewer time than configured) rather
                // than generous, because the two cases are indistinguishable in
                // SQL and granting a free attempt risks an extra send.
                'attempt' => DB::raw('attempt + 1'),
            ]);

        return $claimed === 1 ? $token : null;
    }

    /**
     * Queue a webhook delivery for processing.
     *
     * @param  WebhookEndpoint  $endpoint  The webhook endpoint configuration
     * @param  string  $eventType  The type of event (e.g., 'server.expiry.updated')
     * @param  int|string  $serverId  The ID of the server associated with the event
     * @param  array  $payload  The data payload to send
     */
    public function queueDelivery(
        WebhookEndpoint $endpoint,
        string $eventType,
        int|string $serverId,
        array $payload
    ): void {
        // Only proceed if the endpoint is active and subscribed to this event type
        if (! $endpoint->isActive() || ! $endpoint->isSubscribedToEvent($eventType)) {
            return;
        }

        // Create delivery record. `attempt` counts attempts already consumed, so a
        // new row starts at 0 and the first claim's increment makes it 1 — which is
        // what makes the initial inline send count against max_attempts exactly once.
        $delivery = WebhookDelivery::create([
            'webhook_endpoint_id' => $endpoint->id,
            'event_type' => $eventType,
            'server_id' => (string) $serverId,
            'payload' => $payload,
            'attempt' => 0,
            'max_attempts' => $this->maxAttempts(),
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
     * @param  WebhookDelivery  $delivery  The delivery record to process
     * @return bool True when this worker owned the claim and attempted the send.
     */
    public function attemptDelivery(WebhookDelivery $delivery): bool
    {
        // Take ownership before anything is sent. A losing racer gets null here
        // and must not send: another worker owns this delivery.
        $token = $this->claim($delivery);

        if ($token === null) {
            return false;
        }

        // Re-read so attempt/claim reflect the claimed row, not the stale copy.
        $delivery->refresh();

        // Load the endpoint relationship
        $delivery->load('endpoint');

        $endpoint = $delivery->endpoint;

        // Double-check that endpoint is still active and subscribed
        if (! $endpoint->isActive() || ! $endpoint->isSubscribedToEvent($delivery->event_type)) {
            $this->finalize($delivery, $token, [
                'status' => 'failed',
                'error_message' => 'Webhook endpoint is inactive or unsubscribed from event',
                'processed_at' => now(),
            ]);

            return true;
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
            $signature = WebhookSignature::generate($jsonPayload.$timestamp, $secretKey);

            // Prepare headers
            $headers = [
                'Content-Type' => 'application/json',
                'User-Agent' => 'Pelican-Server-Expiry/2.0',
                'X-Signature' => $signature,
                'X-Timestamp' => $timestamp,
                // Add webhook ID for identification
                'X-Webhook-ID' => strval($endpoint->id),
                // Stable per-delivery identity so a receiver can deduplicate the
                // retries that at-least-once delivery inevitably produces. This
                // only helps if the receiver actually implements that check.
                'X-Webhook-Delivery-ID' => (string) $delivery->id,
            ];

            // Make the HTTP request
            $response = Http::timeout($this->timeoutSeconds())
                ->withHeaders($headers)
                ->post($endpoint->url, $jsonPayload);

            // Check if delivery was successful (2xx status code)
            if ($response->successful()) {
                // The delivery outcome and the endpoint's success tracking are one
                // result: committing the delivery without the endpoint write would
                // leave a success the endpoint never recorded. The request above has
                // already returned, so no network work happens inside this
                // transaction — it holds two row locks for two statements.
                DB::transaction(function () use ($delivery, $token, $response, $endpoint): void {
                    $finalized = $this->finalize($delivery, $token, [
                        'status' => 'success',
                        'http_status_code' => $response->status(),
                        'processed_at' => now(),
                    ]);

                    // Only the worker that still owns the claim writes endpoint state.
                    // A worker whose lease expired and whose row was reclaimed matches
                    // zero rows above; its late 2xx belongs to an outcome it no longer
                    // owns, so it must not reset counters the current owner may have
                    // just incremented.
                    if (! $finalized) {
                        return;
                    }

                    $endpoint->update(['failure_count' => 0, 'last_success_at' => now()]);
                });
            } else {
                throw new \Exception("HTTP {$response->status()}: {$response->body()}");
            }
        } catch (\Throwable $exception) {
            // Handle failure
            $this->handleDeliveryFailure($delivery, $exception, $token);
        }

        return true;
    }

    /**
     * Persist an outcome, but only while this worker still owns the claim.
     *
     * If the lease expired and another worker reclaimed the row, this update
     * matches nothing and the stale worker's late response is discarded instead
     * of corrupting the newer worker's state.
     *
     * @param  array<string, mixed>  $attributes
     * @return bool True when this worker still owned the claim.
     */
    private function finalize(WebhookDelivery $delivery, string $token, array $attributes): bool
    {
        $updated = WebhookDelivery::where('id', $delivery->id)
            ->where('claim_token', $token)
            ->update($attributes + ['claim_token' => null, 'claimed_at' => null]);

        return $updated === 1;
    }

    /**
     * Handle a failed delivery attempt.
     *
     * @param  WebhookDelivery  $delivery  The delivery record that failed
     * @param  \Throwable  $exception  The exception that caused the failure
     */
    private function handleDeliveryFailure(WebhookDelivery $delivery, \Throwable $exception, string $token): void
    {
        // The claim already consumed this attempt, so `attempt` is the number of
        // attempts made so far and `attempt < max_attempts` means sends remain.
        $attempt = $delivery->attempt;
        $maxAttempts = $delivery->max_attempts;

        // Log the failure
        Log::error(
            "Webhook delivery failed for endpoint {$delivery->webhook_endpoint_id}, ".
            "attempt {$attempt}/{$maxAttempts}: {$exception->getMessage()}"
        );

        $httpStatus = $exception instanceof ConnectionException ? null :
            (method_exists($exception, 'getResponse') && $exception->getResponse() ?
             $exception->getResponse()->getStatusCode() : null);

        // Check if we should retry
        if ($attempt < $maxAttempts) {
            $this->finalize($delivery, $token, [
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'http_status_code' => $httpStatus,
                'next_attempt_at' => now()->addSeconds($this->retryDelaySeconds($attempt)),
            ]);

            return;
        }

        // Max attempts reached: the delivery's terminal state and the endpoint's
        // failure total are one outcome. Leaving them as two statements would let a
        // crash commit the delivery without its counter update, and the recovery
        // pass never revisits a row whose `processed_at` is set — so that undercount
        // would be permanent. The HTTP request has already failed by now, so there is
        // no network work inside this transaction.
        DB::transaction(function () use ($delivery, $token, $exception, $httpStatus): void {
            // Only the worker that actually recorded the terminal failure counts it,
            // so a lost claim cannot double-count the endpoint's failure total.
            $finalized = $this->finalize($delivery, $token, [
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'http_status_code' => $httpStatus,
                'processed_at' => now(),
            ]);

            if (! $finalized) {
                return;
            }

            $this->recordEndpointFailure($delivery->endpoint);
        });
    }

    /**
     * Record one endpoint failure.
     *
     * The counter and its timestamp are written by a single statement, so an
     * increment can never be committed without the timestamp that explains it and
     * no read-modify-write of the count happens in PHP. `increment()` is the model
     * path (it fires `updating`/`updated` and routes the addition to the engine),
     * which is why it is used here rather than `update(['failure_count' => DB::raw(…)])`:
     * the latter stores a raw expression as an attribute, and the model's
     * dirty-check casts that expression to int while comparing against the loaded
     * value, which PHP reports as "Object of class … could not be converted to int"
     * — an ErrorException under Laravel's error handler.
     */
    private function recordEndpointFailure(WebhookEndpoint $endpoint): void
    {
        $endpoint->increment('failure_count', 1, ['last_failed_at' => now()]);
    }

    /**
     * Finalize deliveries whose last permitted attempt was consumed by a worker
     * that then died.
     *
     * Such a row cannot be recovered by the retry predicate: the attempt budget is
     * spent, so `attempt < max_attempts` is false and no worker may claim it again.
     * That leaves the dead claim unreleased, `processed_at` null, and the final
     * failure uncounted against the endpoint — permanently, because nothing else
     * writes terminal state. This pass supplies the missing outcome without ever
     * granting another send.
     *
     * The guarded UPDATE is the compare-and-set: the first finisher clears
     * `claim_token`, so a second tick or a second worker matches zero rows and the
     * endpoint's failure total can only be incremented once. A worker that was
     * merely slow rather than dead loses that same race, so its late result cannot
     * overwrite this terminal state (its own `finalize()` no longer matches).
     *
     * Each row is closed out inside one short transaction together with its endpoint
     * counter, and this pass makes no HTTP requests, so no database lock is ever held
     * across network I/O here.
     *
     * Rows that still have budget are deliberately left alone: those are
     * reclaimable and are retried by processPendingDeliveries(). Rows with no claim
     * token are left alone too — without a token there is no evidence a worker died
     * holding the row, so a legacy row is never rewritten on an assumption.
     *
     * @return int Number of stranded deliveries finalized.
     */
    private function finalizeAbandonedExhaustedClaims(): int
    {
        $staleBefore = now()->subSeconds(WebhookDeliveryClaim::LEASE_SECONDS);

        $abandoned = WebhookDelivery::query()
            // A status a worker may hold, and a budget that is fully spent.
            ->whereIn('status', WebhookDeliveryClaim::CLAIMABLE_STATUSES)
            ->whereColumn('attempt', '>=', 'max_attempts')
            // Only a claim that is no longer live can have died with its worker.
            ->whereNotNull('claim_token')
            ->whereNotNull('claimed_at')
            ->where('claimed_at', '<=', $staleBefore)
            ->whereNull('processed_at')
            ->get();

        $finalizedCount = 0;

        foreach ($abandoned as $delivery) {
            // One transaction per row: the ownership CAS and the endpoint total are
            // one outcome, and this pass performs no HTTP at all, so the only rows
            // locked are the two it writes and only for the duration of those writes.
            // A row that fails here is rolled back whole — still holding its dead
            // claim — so the next tick picks it up and retries the finalization
            // instead of leaving a finalized delivery uncounted.
            $finalized = DB::transaction(function () use ($delivery): bool {
                $finalized = WebhookDelivery::where('id', $delivery->id)
                    // The dead worker's token is the ownership check.
                    ->where('claim_token', $delivery->claim_token)
                    ->whereNull('processed_at')
                    ->update([
                        'status' => 'failed',
                        'error_message' => self::ABANDONED_ERROR_MESSAGE,
                        'processed_at' => now(),
                        'claim_token' => null,
                        'claimed_at' => null,
                    ]);

                if ($finalized !== 1) {
                    return false; // Another tick finalized it first.
                }

                // Counted only by the finisher, exactly like handleDeliveryFailure(),
                // so the endpoint's failure total cannot be incremented twice.
                $this->recordEndpointFailure($delivery->endpoint);

                return true;
            });

            if ($finalized) {
                $finalizedCount++;
            }
        }

        return $finalizedCount;
    }

    /**
     * Process pending webhook deliveries that are ready for retry.
     * This method should be called by a scheduler/command.
     *
     * Selects a superset of claimable rows; the atomic claim in
     * attemptDelivery() is what actually decides ownership, so a row that stops
     * being eligible between this SELECT and the claim is simply skipped.
     *
     * @return int Number of deliveries handled: finalized as abandoned plus
     *             claimed and processed.
     */
    public function processPendingDeliveries(): int
    {
        // First close out deliveries whose final attempt died with its worker.
        // They are invisible to the candidate query below (their budget is spent),
        // so skipping this would strand them forever.
        $finalizedCount = $this->finalizeAbandonedExhaustedClaims();

        $now = now();
        $staleBefore = $now->copy()->subSeconds(WebhookDeliveryClaim::LEASE_SECONDS);

        $candidates = WebhookDelivery::whereColumn('attempt', '<', 'max_attempts')
            ->where(function ($query) use ($now, $staleBefore) {
                $query
                    // A retry that has come due.
                    ->where(function ($query) use ($now) {
                        $query->where('status', 'failed')
                            ->where(function ($query) use ($now) {
                                $query->whereNull('next_attempt_at')
                                    ->orWhere('next_attempt_at', '<=', $now);
                            });
                    })
                    // A delivery whose worker died mid-request, and a delivery
                    // whose worker died before it ever took the claim. Both are
                    // recoverable; a fresh unclaimed row is not.
                    ->orWhere(function ($query) use ($staleBefore) {
                        $query->where('status', 'pending')
                            ->where(function ($query) use ($staleBefore) {
                                $query->where(function ($query) use ($staleBefore) {
                                    $query->whereNotNull('claimed_at')
                                        ->where('claimed_at', '<=', $staleBefore);
                                })->orWhere(function ($query) use ($staleBefore) {
                                    $query->whereNull('claimed_at')
                                        ->where('next_attempt_at', '<=', $staleBefore);
                                });
                            });
                    });
            })
            ->orderBy('next_attempt_at')
            ->get();

        $processedCount = 0;

        foreach ($candidates as $delivery) {
            if ($this->attemptDelivery($delivery)) {
                $processedCount++;
            }
        }

        return $finalizedCount + $processedCount;
    }
}
