<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Domain\Events;

/**
 * Base class for all expiration-related domain events.
 * Events are immutable and contain all relevant data.
 */
abstract class ExpirationEvent
{
    /**
     * @param string $serverId The unique identifier of the server
     * @param \DateTimeImmutable $occurredOn When the event occurred
     */
    public function __construct(
        public readonly string $serverId,
        public readonly \DateTimeImmutable $occurredOn
    ) {}

    /**
     * Get the server ID associated with this event.
     */
    public function getServerId(): string
    {
        return $this->serverId;
    }

    /**
     * Get when this event occurred.
     */
    public function getOccurredOn(): \DateTimeImmutable
    {
        return $this->occurredOn;
    }
}