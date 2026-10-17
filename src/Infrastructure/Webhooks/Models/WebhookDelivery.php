<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SquadronStrike\ServerExpiry\Support\WebhookDeliveryClaim;

/**
 * Eloquent model for tracking webhook delivery attempts.
 */
class WebhookDelivery extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'webhook_deliveries';

    /**
     * Migration 005 creates this table WITHOUT created_at/updated_at columns
     * (timing is tracked by the explicit queued_at/processed_at/
     * next_attempt_at columns). Leaving Eloquent timestamps enabled made
     * every INSERT/UPDATE emit those nonexistent columns and fail with
     * "Unknown column 'updated_at'" — no webhook delivery could ever be
     * recorded (H6.3 E2E runtime finding).
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'webhook_endpoint_id',
        'event_type',
        'server_id',
        'payload',
        'attempt',
        'max_attempts',
        'status',
        'http_status_code',
        'error_message',
        'queued_at',
        'processed_at',
        'next_attempt_at',
        'claim_token',
        'claimed_at',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'payload' => 'array',
        'attempt' => 'integer',
        'max_attempts' => 'integer',
        'http_status_code' => 'integer',
        'queued_at' => 'datetime',
        'processed_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'claimed_at' => 'datetime',
    ];

    /**
     * Get the webhook endpoint that owns this delivery.
     *
     * Named `endpoint` (not `webhookEndpoint`) so Laravel does not treat the
     * studly method name as an Attribute accessor for `webhook_endpoint`,
     * which would break isRelation() and Filament v5 relationship filters.
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /**
     * Check if the delivery is pending processing.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the delivery was successful.
     */
    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    /**
     * Check if the delivery has failed.
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Whether a live worker currently holds this delivery's claim.
     *
     * "Processing" is a derived state rather than a `status` value: the stored
     * status is `pending` while a worker owns the row, and the claim lease is
     * what distinguishes "in flight" from "abandoned".
     */
    public function isProcessing(): bool
    {
        return $this->status !== 'success' && WebhookDeliveryClaim::isClaimed($this->claimed_at, now());
    }

    /**
     * Whether this row's claim has been abandoned by its worker.
     */
    public function isClaimStale(): bool
    {
        return $this->claimed_at !== null && WebhookDeliveryClaim::isStale($this->claimed_at, now());
    }

    /**
     * Check if the delivery can be retried.
     *
     * `attempt` counts attempts already consumed, so a failed row still has
     * budget when its count is below the limit.
     *
     * @return bool True if delivery can be retried, false otherwise
     */
    public function canRetry(): bool
    {
        return $this->isFailed() && $this->attempt < $this->max_attempts;
    }

    /**
     * Format the payload for display (pretty-printed JSON).
     */
    protected function payload(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => is_string($value) ? json_decode($value, true) : $value,
        );
    }
}
