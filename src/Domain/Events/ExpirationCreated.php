<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Domain\Events;

use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\ExpirationDate;

/**
 * Domain event fired when a server's expiration date is initially created.
 */
final class ExpirationCreated extends ExpirationEvent
{
    /**
     * @param string $serverId The unique identifier of the server
     * @param ExpirationDate $expirationDate The new expiration date
     * @param \DateTimeImmutable $occurredOn When the event occurred
     */
    public function __construct(
        string $serverId,
        public readonly ExpirationDate $expirationDate,
        \DateTimeImmutable $occurredOn
    ) {
        parent::__construct($serverId, $occurredOn);
    }
}