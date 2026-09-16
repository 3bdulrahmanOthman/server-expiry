<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Filament\Server\Pages;

use App\Enums\TablerIcon;
use App\Filament\Server\Pages\ServerFormPage;
use App\Models\Server;
use BackedEnum;
use Filament\Forms;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use SquadronStrike\ServerExpiry\Application\Services\ExpirationService;
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
                        Placeholder::make('status_icon')
                            ->label(trans('server-expiry::strings.status_label'))
                            ->content(fn (?Server $record): string => $record !== null ? match (Expiry::statusColor($record)) {
                                'gray' => '<svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>',
                                'success' => '<svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
                                'warning' => '<svg class="w-5 h-5 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c.77-1.333-.268-2.853-1.732-3H6.938c-.77 1.333-2.202 1.667-1.732 3z"/></svg>',
                                'danger' => '<svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c.77-1.333-.268-2.853-1.732-3H6.938c-.77 1.333-2.202 1.667-1.732 3z"/></svg>',
                                default => '<svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
                            } : '<svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>')
                            // Static, project-owned SVG markup — safe to render unescaped.
                            ->html()
                            ->columnSpan([1]),
                        Placeholder::make('status_text')
                            ->label('')
                            ->content(fn (?Server $record): string => $record !== null ? Expiry::statusText($record) : '')
                            ->columnSpan([2]),
                        // Expiration date
                        Forms\Components\Placeholder::make('expires_at')
                            ->label(trans('server-expiry::strings.field_label'))
                            ->content(fn (?Server $record): ?string => $record !== null && $record->expires_at ? Carbon::parse($record->expires_at)->format('Y-m-d H:i') : null)
                            ->placeholder(trans('server-expiry::strings.column_permanent'))
                            ->columnSpan([1]),
                        // Time remaining
                        Forms\Components\Placeholder::make('time_remaining')
                            ->label(trans('server-expiry::strings.remaining_label'))
                            ->content(fn (?Server $record): string => $record !== null ? Expiry::remainingText($record) : trans('server-expiry::strings.column_permanent'))
                            ->columnSpan([1]),
                        // Expired status badge
                        Forms\Components\Placeholder::make('is_expired')
                            ->label('Expired')
                            ->content(fn (?Server $record): string => $record !== null ? Expiry::isExpired($record) ?
                                '<span class="inline-flex items-center rounded-full bg-red-500/10 px-2.5 py-0.5 text-xs font-semibold text-red-600">Yes</span>' :
                                '<span class="inline-flex items-center rounded-full bg-green-500/10 px-2.5 py-0.5 text-xs font-semibold text-green-600">No</span>' : '')
                            ->html()
                            ->columnSpan([1]),
                        // Grace period status badge
                        Forms\Components\Placeholder::make('in_grace_period')
                            ->label('In Grace Period')
                            ->content(fn (?Server $record): string => $record !== null ? app(ExpirationService::class)->isInGracePeriod($record->getKey()) ?
                                '<span class="inline-flex items-center rounded-full bg-yellow-500/10 px-2.5 py-0.5 text-xs font-semibold text-yellow-600">Yes</span>' :
                                '<span class="inline-flex items-center rounded-full bg-green-500/10 px-2.5 py-0.5 text-xs font-semibold text-green-600">No</span>' : '')
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
                            ->content(fn (?Server $record): string => $record !== null ? $record->isSuspended() ?
                                '<span class="inline-flex items-center rounded-full bg-red-500/10 px-2.5 py-0.5 text-xs font-semibold text-red-600">Suspended</span>' :
                                '<span class="inline-flex items-center rounded-full bg-green-500/10 px-2.5 py-0.5 text-xs font-semibold text-green-600">Not Suspended</span>' : '')
                            ->html()
                            ->columnSpanFull(),

                        // Suspension reason
                        Forms\Components\Placeholder::make('suspension_reason')
                            ->label(trans('server-expiry::strings.suspension_reason_label'))
                            ->content(fn (?Server $record): string => $record !== null && $record->suspension_reason ? ucfirst($record->suspension_reason) : 'none')
                            ->visible(fn (?Server $record): bool => $record !== null && $record->isSuspended() &&
                                in_array($record->suspension_reason ?? '', ['expiration', 'manual', 'other']))
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (?Server $record): bool => $record !== null && $record->isSuspended())
                    ->columnSpanFull(),
            ]);
    }

    public function getHeaderActions(): array
    {
        // Clients cannot change their server's expiration date (see README:
        // renewal is provider-only). Admin-side controls live in the admin
        // panel, so the client page intentionally exposes no header actions.
        return [];
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
