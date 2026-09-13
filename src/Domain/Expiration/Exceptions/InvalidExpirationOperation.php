<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Domain\Expiration\Exceptions;

/**
 * Exception thrown when attempting an invalid operation on an expiration.
 * For example, trying to extend a permanent expiration.
 */
class InvalidExpirationOperation extends ExpirationException
{
    public static function cannotExtendPermanent(): self
    {
        return new self('Cannot extend a permanent expiration for a permanent server');
    }

    public static function cannotClearNonExpiring(): self
    {
        return new self('Cannot clear expiration for a server that has no expiration set');
    }

    public static function invalidExpirationDate(string $reason): self
    {
        return new self(sprintf('Invalid expiration date: %s', $reason));
    }
}