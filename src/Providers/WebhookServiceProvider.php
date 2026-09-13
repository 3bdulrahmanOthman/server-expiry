<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Providers;

use Illuminate\Support\ServiceProvider;
use SquadronStrike\ServerExpiry\Domain\Events\ExpirationCreated;
use SquadronStrike\ServerExpiry\Domain\Events\ExpirationSet;
use SquadronStrike\ServerExpiry\Domain\Events\ExpirationCleared;
use SquadronStrike\ServerExpiry\Domain\Events\ServerRenewed;
use SquadronStrike\ServerExpiry\Domain\Events\ExpirationWarning;
use SquadronStrike\ServerExpiry\Domain\Events\ServerSuspendedByExpiration;
use SquadronStrike\ServerExpiry\Domain\Events\ServerExpired;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Listeners\SendExpirationCreatedWebhook;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Listeners\SendExpirationSetWebhook;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Listeners\SendExpirationClearedWebhook;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Listeners\SendServerRenewedWebhook;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Listeners\SendExpirationWarningWebhook;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Listeners\SendServerSuspendedByExpirationWebhook;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Listeners\SendServerExpiredWebhook;
use Illuminate\Support\Facades\Event;

/**
 * Service provider for registering webhook event listeners.
 */
class WebhookServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register webhook listeners for expiration lifecycle events
        Event::listen(
            ExpirationCreated::class,
            SendExpirationCreatedWebhook::class
        );

        Event::listen(
            ExpirationSet::class,
            SendExpirationSetWebhook::class
        );

        Event::listen(
            ExpirationCleared::class,
            SendExpirationClearedWebhook::class
        );

        Event::listen(
            ServerRenewed::class,
            SendServerRenewedWebhook::class
        );

        Event::listen(
            ExpirationWarning::class,
            SendExpirationWarningWebhook::class
        );

        Event::listen(
            ServerSuspendedByExpiration::class,
            SendServerSuspendedByExpirationWebhook::class
        );

        // ServerExpired event listener (defined but not currently used in Phase 7)
        Event::listen(
            ServerExpired::class,
            SendServerExpiredWebhook::class
        );
    }
}