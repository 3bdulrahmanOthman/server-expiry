<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Domain\Expiration\Enums;

enum SuspensionType: string
{
    case NONE = 'none';
    case EXPIRATION = 'expiration';
    case MANUAL = 'manual';
    case OTHER = 'other';
}