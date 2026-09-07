<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Models\WebhookEndpoint;

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
    ];

    /**
     * Get the webhook endpoint that owns this delivery.
     */
    public function webhookEndpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /**
     * Check if the delivery is pending processing.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the delivery was successful.
     *
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    /**
     * Check if the delivery has failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if the delivery can be retried.
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