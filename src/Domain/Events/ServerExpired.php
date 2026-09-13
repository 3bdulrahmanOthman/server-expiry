<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Domain\Events;

/**
 * Domain event fired when a server reaches its expiration date.
 */
final class ServerExpired extends ExpirationEvent
{
    /**
     * @param string $serverId The unique identifier of the server
     * @param \DateTimeImmutable $occurredOn When the event occurred
     */
    public function __construct(
        string $serverId,
        \DateTimeImmutable $occurredOn
    ) {
        parent::__construct($serverId, $occurredOn);
    }
}