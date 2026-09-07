<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Listeners;

use SquadronStrike\ServerExpiry\Domain\Events\ExpirationSet;
use Illuminate\Support\Facades\Log;

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