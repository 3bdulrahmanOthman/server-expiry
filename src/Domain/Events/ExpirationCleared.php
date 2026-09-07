<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Domain\Events;

/**
 * Domain event fired when a server's expiration date is cleared (made permanent).
 */
final class ExpirationCleared extends ExpirationEvent
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