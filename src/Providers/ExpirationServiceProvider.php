<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Providers;

use Illuminate\Support\ServiceProvider;
use SquadronStrike\ServerExpiry\Application\Contracts\ExpirationRepository;
use SquadronStrike\ServerExpiry\Infrastructure\Persistence\EloquentExpirationRepository;
use SquadronStrike\ServerExpiry\Listeners\ExpirationSetListener;
use SquadronStrike\ServerExpiry\Listeners\ExpirationClearedListener;
use SquadronStrike\ServerExpiry\Listeners\ServerRenewedListener;
use SquadronStrike\ServerExpiry\Listeners\ExpirationWarningListener;
use SquadronStrike\ServerExpiry\Listeners\ServerSuspendedByExpirationListener;
use Illuminate\Support\Facades\Event;

/**
 * Service provider for binding expiration repository contracts to implementations.
 */
class ExpirationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Bind the expiration repository contract to its Eloquent implementation
        $this->app->singleton(ExpirationRepository::class, function ($app) {
            return new EloquentExpirationRepository();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register event listeners for expiration lifecycle events
        Event::listen(
            ExpirationSet::class,
            ExpirationSetListener::class
        );

        Event::listen(
            ExpirationCleared::class,
            ExpirationClearedListener::class
        );

        Event::listen(
            ServerRenewed::class,
            ServerRenewedListener::class
        );

        Event::listen(
            ExpirationWarning::class,
            ExpirationWarningListener::class
        );

        Event::listen(
            ServerSuspendedByExpiration::class,
            ServerSuspendedByExpirationListener::class
        );
    }
}