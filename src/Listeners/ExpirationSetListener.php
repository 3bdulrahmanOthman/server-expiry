<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Listeners;

use Illuminate\Support\Facades\Log;
use SquadronStrike\ServerExpiry\Domain\Events\ExpirationSet;

/**
 * Handle the ExpirationSet event.
 */
class ExpirationSetListener
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
    public function handle(ExpirationSet $event): void
    {
        Log::debug("Server Expiry Plugin: Expiration set for server ID {$event->serverId} to {$event->expirationDate}.");
    }
}
