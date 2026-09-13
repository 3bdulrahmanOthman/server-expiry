<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Domain\Expiration\Enums;

enum ExpirationStatus: int
{
    case PERMANENT = 0;
    case ACTIVE = 1;
    case WARNING = 2;
    case EXPIRED = 3;
    case GRACE = 4;
    case SUSPENDED = 5;
}