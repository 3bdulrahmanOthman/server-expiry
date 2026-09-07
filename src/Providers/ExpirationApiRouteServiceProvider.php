<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * API route provider for the Server Expiry plugin.
 */
class ExpirationApiRouteServiceProvider extends RouteServiceProvider
{
    /**
     * Define the routes for the application.
     */
    public function boot(): void
    {
        $this->routes(function () {
            // Application API routes (admin/API)
            Route::middleware(['api', 'application-api', 'auth:application'])
                ->prefix('/api/application/servers/{server}')
                ->scopeBindings()
                ->group(function () {
                    Route::get('/expiration', [\SquadronStrike\ServerExpiry\Http\Controllers\Api\Application\Servers\ExpirationController::class, 'show'])
                        ->name('application.servers.expiration.show');
                        
                    Route::put('/expiration', [\SquadronStrike\ServerExpiry\Http\Controllers\Api\Application\Servers\ExpirationController::class, 'update'])
                        ->name('application.servers.expiration.update');
                        
                    Route::post('/expiration/extend', [\SquadronStrike\ServerExpiry\Http\Controllers\Api\Application\Servers\ExpirationController::class, 'extend'])
                        ->name('application.servers.expiration.extend');
                        
                    Route::post('/renew', [\SquadronStrike\ServerExpiry\Http\Controllers\Api\Application\Servers\ExpirationController::class, 'renew'])
                        ->name('application.servers.renew');
                        
                    Route::delete('/expiration', [\SquadronStrike\ServerExpiry\Http\Controllers\Api\Application\Servers\ExpirationController::class, 'destroy'])
                        ->name('application.servers.expiration.destroy');
                });
        });
    }
}
