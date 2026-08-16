<?php

namespace PelicanDev\ServerExpiry\Filament\Server\Pages;

use App\Enums\TablerIcon;
use App\Filament\Server\Pages\ServerFormPage;
use App\Models\Server;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use PelicanDev\ServerExpiry\Support\Expiry;

/**
 * Client-facing "Expiration" page for a single server. Registered on the
 * server panel through Plugin::register() -> $panel->discoverPages().
 *
 * This page is informational only: it shows the expiration status, the
 * configured date, the time remaining and the plugin's warning/suspension
 * policy. The expiration date itself can only be changed by the provider via
 * the admin panel (Edit Server -> Expiration tab).
 */
class ExpirySettingsPage extends ServerFormPage
{
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::CalendarExclamation;

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'expiry-settings';

    protected string $view = 'server-expiry::filament.server.pages.expiry-settings';

    public function form(Schema $schema): Schema
    {
        return parent::form($schema)
            ->components([
                Section::make(trans('server-expiry::strings.section_title'))
                    ->description(trans('server-expiry::strings.section_description'))
                    ->columnSpanFull()
                    ->columns([
                        'default' => 1,
                        'sm' => 2,
                        'lg' => 3,
                    ])
                    ->schema([
                        TextEntry::make('expiry_status')
                            ->label(trans('server-expiry::strings.status_label'))
                            ->badge()
                            ->color(fn (Server $record): string => Expiry::statusColor($record))
                            ->state(fn (Server $record): string => Expiry::statusText($record))
                            ->columnSpanFull(),
                        TextEntry::make('expires_at')
                            ->label(trans('server-expiry::strings.field_label'))
                            ->placeholder(trans('server-expiry::strings.column_permanent'))
                            ->formatStateUsing(fn ($state): ?string => $state ? Carbon::parse($state)->format('Y-m-d H:i') : null)
                            ->columnSpan(1),
                        TextEntry::make('time_remaining')
                            ->label(trans('server-expiry::strings.remaining_label'))
                            ->state(fn (Server $record): string => Expiry::remainingText($record))
                            ->columnSpan(1),
                        TextEntry::make('notify_schedule')
                            ->label(trans('server-expiry::strings.warning_label'))
                            ->state(fn (): string => Expiry::warningScheduleText())
                            ->columnSpan(1),
                        TextEntry::make('policy')
                            ->label(trans('server-expiry::strings.policy_label'))
                            ->state(function (): string {
                                $hours = (int) config('server-expiry.grace_period_hours', 0);

                                return $hours > 0
                                    ? trans('server-expiry::strings.policy_helper_grace', ['hours' => $hours])
                                    : trans('server-expiry::strings.policy_helper');
                            })
                            ->columnSpanFull(),
                    ]),
            ]);
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
