<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Filament\Server\Pages;

use App\Enums\TablerIcon;
use App\Filament\Server\Pages\ServerFormPage;
use App\Models\Server;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Form;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use SquadronStrike\ServerExpiry\Application\Services\ExpirationService;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\ExpirationDate;
use SquadronStrike\ServerExpiry\Support\Expiry;

/**
 * Client-facing "Expiration" page for a single server. Registered on the
 * server panel through Plugin::register() -> $panel->discoverPages().
 *
 * This page shows expiration information and allows clients to renew their
 * server if authorized.
 */
class ExpirySettingsPage extends ServerFormPage
{
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::CalendarExclamation;

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'expiry-settings';

    protected string $view = 'server-expiry::filament.server.pages.expiry-settings';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'default' => 1,
                'sm' => 2,
                'lg' => 3,
            ])
            ->schema([
                // Status section with visual indicator
                Section::make(trans('server-expiry::strings.section_title'))
                    ->schema([
                        Forms\Components\Placeholder::make('status_icon')
                            ->label(trans('server-expiry::strings.status_label'))
                            ->content(fn (Server $record): string => match (Expiry::statusColor($record)) {
                                'gray' => '<svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V9a2 2 0 002-2H5a2 2 0 002 2v10a2 2 0 002 2z"/></svg>',
                                'success' => '<svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
                                'warning' => '<svg class="w-5 h-5 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c.77-1.333-.268-2.853-1.732-3H6.938c-.77 1.333-2.202 1.667-1.732 3z"/></svg>',
                                'danger' => '<svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c.77-1.333-.268-2.853-1.732-3H6.938c-.77 1.333-2.202 1.667-1.732 3z"/></svg>',
                                default => '<svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
                            })
                            ->columnSpan([1]),
                        Forms\Components\Placeholder::make('status_text')
                            ->label('')
                            ->content(fn (Server $record): string => Expiry::statusText($record))
                            ->columnSpan([2]),
                    ])
                    ->columns([3])
                    ->columnSpanFull(),

                // Expiration details section
                Section::make(trans('server-expiry::strings.expiration_details'))
                    ->schema([
                        // Expiration date
                        Forms\Components\Placeholder::make('expires_at')
                            ->label(trans('server-expiry::strings.field_label'))
                            ->content(fn (Server $record): ?string =>
                                $record->expires_at ? Carbon::parse($record->expires_at)->format('Y-m-d H:i') : null)
                            ->placeholder(trans('server-expiry::strings.column_permanent'))
                            ->columnSpan([1]),
                        // Time remaining
                        Forms\Components\Placeholder::make('time_remaining')
                            ->label(trans('server-expiry::strings.remaining_label'))
                            ->content(fn (Server $record): string => Expiry::remainingText($record))
                            ->columnSpan([1]),
                        // Expired status badge
                        Forms\Components\Placeholder::make('is_expired')
                            ->label('Expired')
                            ->content(fn (Server $record): string => Expiry::isExpired($record) ?
                                '<span class="badge badge-danger">Yes</span>' :
                                '<span class="badge badge-success">No</span>')
                            ->html()
                            ->columnSpan([1]),
                        // Grace period status badge
                        Forms\Components\Placeholder::make('in_grace_period')
                            ->label('In Grace Period')
                            ->content(fn (Server $record): string =>
                                app(ExpirationService::class)->isInGracePeriod($record->getKey()) ?
                                '<span class="badge badge-warning">Yes</span>' :
                                '<span class="badge badge-success">No</span>')
                            ->html()
                            ->columnSpan([1]),
                    ])
                    ->columns([2])
                    ->columnSpanFull(),

                // Suspension info section (if applicable)
                Section::make(trans('server-expiry::strings.suspension_info'))
                    ->schema([
                        // Suspension status
                        Forms\Components\Placeholder::make('suspension_status')
                            ->label('Suspension Status')
                            ->content(fn (Server $record): string =>
                                $record->isSuspended() ?
                                '<span class="badge badge-danger">Suspended</span>' :
                                '<span class="badge badge-success">Not Suspended</span>')
                            ->html()
                            ->columnSpanFull(),

                        // Suspension reason
                        Forms\Components\Placeholder::make('suspension_reason')
                            ->label(trans('server-expiry::strings.suspension_reason_label'))
                            ->content(fn (Server $record): string =>
                                $record->suspension_reason ? ucfirst($record->suspension_reason) : 'none')
                            ->visible(fn (Server $record): bool =>
                                $record->isSuspended() &&
                                in_array($record->suspension_reason ?? '', ['expiration', 'manual', 'other']))
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (?Server $record): bool => $record !== null && $record->isSuspended())
                    ->columnSpanFull(),
            ]);
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('renew')
                ->label(trans('server-expiry::strings.action_renew'))
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->form([
                    // This action will use a modal form for renewal options
                ])
                ->action(function (array $data, Server $record) {
                    // Authorization check: ensure user can renew this server
                    if (! Gate::authorize('renew', $record)) {
                        Notification::make()
                            ->danger()
                            ->body('You are not authorized to renew this server.')
                            ->send();
                        return;
                    }

                    $expirationService = app(ExpirationService::class);

                    // Get current expiration
                    $currentExpiration = $expirationService->getExpiration($record->getKey());
                    $now = new \DateTimeImmutable('now');

                    // Determine new expiration date from now
                    if ($currentExpiration->isPermanent()) {
                        // If permanent, set expiration to 30 days from now
                        $newDateTime = $now->add(new \DateInterval('P30D'));
                    } else {
                        // If already expiring, extend by 30 days
                        $newDateTime = $currentExpiration->getDateTime()->add(new \DateInterval('P30D'));
                    }

                    $newExpiration = ExpirationDate::fromDateTime($newDateTime);

                    // Perform renewal
                    $expirationService->renew($record->getKey(), $newExpiration);

                    // Send success notification
                    Notification::make()
                        ->success()
                        ->body(trans('server-expiry::strings.settings_saved'))
                        ->send();
                })
                ->requiresConfirmation()
                ->modalWidth('md')
                ->modalSubmitActionLabel(trans('server-expiry::strings.action_renew'))
                ->modalCancelActionLabel('Cancel')
                ->modalDescription(
                    'Renewing your server will set a new expiration date. ' .
                    'If the server is currently suspended due to expiration, ' .
                    'it will be automatically renewed.'
                )
                ->visible(fn (Server $record): bool =>
                    // Show renewal button if:
                    // 1. Server is not suspended, OR
                    // 2. Server is suspended due to expiration (can be auto-renewed), OR
                    // 3. Owner notifications are enabled (as a fallback)
                    !$record->isSuspended() ||
                    $record->suspension_reason === 'expiration' ||
                    app(ExpirationService::class)->isNotifyOwnerOnSuspendEnabled()
                )
                ->tooltip(trans('server-expiry::strings.action_renew_tooltip')),
            Action::make('set_expiration')
                ->label(trans('server-expiry::strings.action_set_expiration'))
                ->icon('heroicon-o-calendar-plus')
                ->color('primary')
                ->form([
                    Forms\Components\DateTimePicker::make('expires_at')
                        ->label(trans('server-expiry::strings.field_label'))
                        ->placeholder(trans('server-expiry::strings.field_placeholder'))
                        ->helperText(trans('server-expiry::strings.field_helper'))
                        ->nullable()
                        ->seconds(false)
                        ->native(false)
                        ->rule('after_or_equal:today')
                ])
                ->action(function (array $data, Server $record) {
                    // Authorization check: ensure user can set expiration for this server
                    if (! Gate::authorize('renew', $record)) {
                        Notification::make()
                            ->danger()
                            ->body('You are not authorized to modify expiration for this server.')
                            ->send();
                        return;
                    }

                    $expirationService = app(ExpirationService::class);
                    $expirationService->setExpiration(
                        $record->getKey(),
                        ExpirationDate::fromDateTime(Carbon::parse($data['expires_at']))
                    );

                    Notification::make()
                        ->success()
                        ->body(trans('server-expiry::strings.settings_saved'))
                        ->send();
                })
                ->requiresConfirmation()
                ->modalWidth('md')
                ->modalSubmitActionLabel(trans('server-expiry::strings.action_set_expiration'))
                ->modalCancelActionLabel('Cancel')
                ->visible(fn (Server $record): bool =>
                    // Show set expiration button if:
                    // 1. Server is not suspended, OR
                    // 2. Server is suspended due to expiration (can be modified), OR
                    // 3. Owner notifications are enabled (as a fallback)
                    !$record->isSuspended() ||
                    $record->suspension_reason === 'expiration' ||
                    app(ExpirationService::class)->isNotifyOwnerOnSuspendEnabled()
                )
                ->tooltip(trans('server-expiry::strings.action_set_expiration_tooltip'))
        ];
    }

    protected function getServerUrl(int $serverId): string
    {
        return url("/server/{$serverId}");
    }

    public function getTitle(): string
    {
        return trans('server-expiry::strings.settings_title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('server-expiry::strings.settings_nav_label');
    }
}