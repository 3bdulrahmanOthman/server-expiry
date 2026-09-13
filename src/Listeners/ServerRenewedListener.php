<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Listeners;

use SquadronStrike\ServerExpiry\Domain\Events\ServerRenewed;
use Illuminate\Support\Facades\Log;

/**
 * Handle the ServerRenewed event.
 */
class ServerRenewedListener
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
    public function handle(ServerRenewed $event): void
    {
        Log::debug("Server Expiry Plugin: Server ID {$event->serverId} renewed to {$event->expirationDate}.");
    }
}