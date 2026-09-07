<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Domain\Events;

use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\WarningThreshold;

/**
 * Domain event fired when a warning notification is successfully sent for a server.
 */
final class ExpirationWarning extends ExpirationEvent
{
    /**
     * @param string $serverId The unique identifier of the server
     * @param WarningThreshold $threshold The warning threshold that was triggered
     * @param \DateTimeImmutable $occurredOn When the event occurred
     */
    public function __construct(
        string $serverId,
        public readonly WarningThreshold $threshold,
        \DateTimeImmutable $occurredOn
    ) {
        parent::__construct($serverId, $occurredOn);
    }
}