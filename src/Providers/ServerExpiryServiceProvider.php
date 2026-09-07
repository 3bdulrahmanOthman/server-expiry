<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Providers;

use App\Enums\TablerIcon;
use App\Livewire\AlertBanner;
use App\Models\Server;
use Facade\FlareClient\Stacktrace;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use SquadronStrike\ServerExpiry\Console\Commands\SendExpiryWarningsCommand;
use SquadronStrike\ServerExpiry\Console\Commands\SuspendExpiredServersCommand;
use SquadronStrike\ServerExpiry\Support\Expiry;
use SquadronStrike\ServerExpiry\Support\SuspensionContext;

/**
 * Auto-discovered service provider (Pelican scans src/Providers/).
 *
 * The plugin's console commands are registered automatically by Pelican, and
 * are wired into Laravel's scheduler here so the panel's existing cron
 * (`php artisan schedule:run`) drives both lifecycle stages:
 *   - pre-expiration: SendExpiryWarningsCommand sends 7/3/1-day warnings
 *   - post-expiration: SuspendExpiredServersCommand suspends via Wings API
 */
class ServerExpiryServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Schedule::command(SendExpiryWarningsCommand::class)
            ->everyMinute()
            ->withoutOverlapping();

        Schedule::command(SuspendExpiredServersCommand::class)
            ->everyMinute()
            ->withoutOverlapping();

        // Replace Pelican's generic "server conflict" banner with a dedicated
        // expiration banner whenever a server was suspended because its
        // expiration date passed. The hook runs for every panel, but the
        // tenant (a Server) is only set on the client/server panel, so the
        // admin panel is unaffected.
        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_START,
            function (): string {
                /** @var Server|null $server */
                $server = Filament::getTenant();

                if (! $server instanceof Server || ! Expiry::isExpirySuspended($server)) {
                    return '';
                }

                if (Livewire::isLivewireRequest()) {
                    return '';
                }

                // Drop the generic banner Pelican pushes on the console page
                // when a server is in a conflicting (e.g. suspended) state.
                session()->put('alert-banners', array_values(array_filter(
                    session()->get('alert-banners', []),
                    static fn (array $banner): bool => ($banner['id'] ?? null) !== 'server_conflict',
                )));

                AlertBanner::make('server_expiry_suspended')
                    ->title(trans('server-expiry::strings.banner_title'))
                    ->body(trans('server-expiry::strings.banner_body', [
                        'date' => Carbon::parse($server->expires_at)->format('Y-m-d H:i'),
                    ]))
                    ->icon(TablerIcon::AlertTriangle)
                    ->status('danger')
                    ->closable()
                    ->send();

                return '';
            },
        );

        // Listen for Server model updates to set suspension reason based on context.
        Server::updating(function (Server $server) {
            // Determine if status is changing to suspended.
            $originalStatus = $server->getOriginal('status');
            $newStatus = $server->status;

            // Import ServerState here to avoid top-level use if not needed.
            $suspendedValue = \App\Enums\ServerState::Suspended->value;

            if ($newStatus === $suspendedValue && $originalStatus !== $suspendedValue) {
                // Status is changing to suspended.
                if (SuspensionContext::isExpirationSuspensionInProgress()) {
                    $server->suspension_reason = 'expiration';
                } else {
                    // Assume manual suspension if not triggered by our expiration command.
                    $server->suspension_reason = 'manual';
                }
                // Clear the flag after use.
                SuspensionContext::setExpirationSuspensionInProgress(false);
            }

            // Determine if status is changing from suspended to not suspended.
            if ($originalStatus === $suspendedValue && $newStatus !== $suspendedValue) {
                // Server is being unsuspended.
                $server->suspension_reason = null;
            }
        });
    }
}