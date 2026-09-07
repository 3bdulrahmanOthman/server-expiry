<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Listeners;

use SquadronStrike\ServerExpiry\Domain\Events\ExpirationCleared;
use Illuminate\Support\Facades\Log;

/**
 * Handle the ExpirationCleared event.
 */
class ExpirationClearedListener
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(ExpirationCleared $event): void
    {
        Log::debug("Server Expiry Plugin: Expiration cleared for server ID {$event->serverId}.");
    }
}