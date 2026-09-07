<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Providers;

use Illuminate\Support\ServiceProvider;
use SquadronStrike\ServerExpiry\Application\Contracts\ExpirationRepository;
use SquadronStrike\ServerExpiry\Infrastructure\Persistence\EloquentExpirationRepository;

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
        //
    }
}