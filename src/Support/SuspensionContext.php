<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Support;

/**
 * Simple context holder to track if an expiration-based suspension is in progress.
 * Used to distinguish between manual and expiration suspensions in model events.
 */
final class SuspensionContext
{
    private static bool $isExpirationSuspensionInProgress = false;

    private function __construct()
    {
    }

    public static function setExpirationSuspensionInProgress(bool $flag): void
    {
        self::$isExpirationSuspensionInProgress = $flag;
    }

    public static function isExpirationSuspensionInProgress(): bool
    {
        return self::$isExpirationSuspensionInProgress;
    }
}