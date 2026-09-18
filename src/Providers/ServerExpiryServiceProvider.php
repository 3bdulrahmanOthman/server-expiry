<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Providers;

use App\Enums\TablerIcon;
use App\Livewire\AlertBanner;
use App\Models\Server;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use SquadronStrike\ServerExpiry\Console\Commands\ProcessServerExpirationCommand;
use SquadronStrike\ServerExpiry\Console\Commands\ProcessWebhookDeliveriesCommand;
use SquadronStrike\ServerExpiry\Support\Expiry;
use SquadronStrike\ServerExpiry\Support\SuspensionContext;

/**
 * Auto-discovered service provider (Pelican scans src/Providers/).
 *
 * The plugin's console commands are registered automatically by Pelican. The
 * consolidated lifecycle command is wired into Laravel's scheduler here so
 * the panel's existing cron (`php artisan schedule:run`) drives both stages
 * (pre-expiration warnings and post-grace suspension) in a single run.
 */
class ServerExpiryServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Schedule::command(ProcessServerExpirationCommand::class)
            ->everyMinute()
            ->withoutOverlapping();

        // Retry failed webhook deliveries with exponential backoff. Idempotent:
        // only deliveries whose next_attempt_at is due are attempted.
        Schedule::command(ProcessWebhookDeliveriesCommand::class)
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

            // `status` is cast to the ServerState enum, so live values arrive as
            // enum instances while raw originals may still be plain strings —
            // normalize both before comparing, otherwise the strict string
            // comparisons below never match and the reason stamping/clearing
            // silently never runs (H6.1 runtime finding).
            $newStatusValue = $newStatus instanceof \App\Enums\ServerState ? $newStatus->value : $newStatus;
            $originalStatusValue = $originalStatus instanceof \App\Enums\ServerState ? $originalStatus->value : $originalStatus;

            if ($newStatusValue === $suspendedValue && $originalStatusValue !== $suspendedValue) {
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
            if ($originalStatusValue === $suspendedValue && $newStatusValue !== $suspendedValue) {
                // Server is being unsuspended.
                $server->suspension_reason = null;
            }
        });
    }
}
