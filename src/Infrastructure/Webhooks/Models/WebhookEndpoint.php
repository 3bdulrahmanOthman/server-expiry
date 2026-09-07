<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Models\WebhookDelivery;

/**
 * Eloquent model for webhook endpoint configurations.
 */
class WebhookEndpoint extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'webhook_endpoints';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'url',
        'secret_key',
        'events',
        'active',
        'failure_count',
        'last_failed_at',
        'last_success_at',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'events' => 'array',
        'active' => 'boolean',
        'failure_count' => 'integer',
        'last_failed_at' => 'datetime',
        'last_success_at' => 'datetime',
    ];

    /**
     * Get the deliveries for this endpoint.
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'webhook_endpoint_id');
    }

    /**
     * Check if the endpoint is active.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->active === true;
    }

    /**
     * Check if the endpoint is subscribed to a specific event type.
     *
     * @param string $eventType The event type to check (e.g., 'server.expiry.updated')
     * @return bool True if subscribed, false otherwise
     */
    public function isSubscribedToEvent(string $eventType): bool
    {
        $events = $this->events ?? [];
        return in_array($eventType, $events, true);
    }

    /**
     * Format the secret key for display (masked for security).
     */
    protected function secretKey(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? str_repeat('*', min(8, strlen($value))) : null,
        );
    }
}