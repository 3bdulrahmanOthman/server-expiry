<?php

namespace PelicanDev\ServerExpiry;

use App\Contracts\Plugins\HasPluginSettings;
use App\Enums\StepPosition;
use App\Enums\SuspendAction;
use App\Enums\TablerIcon;
use App\Enums\TabPosition;
use App\Filament\Admin\Resources\Servers\Pages\CreateServer;
use App\Filament\Admin\Resources\Servers\Pages\EditServer;
use App\Filament\Admin\Resources\Servers\ServerResource;
use App\Models\Server;
use App\Services\Servers\SuspensionService;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Wizard\Step;
use PelicanDev\ServerExpiry\Filament\Admin\Resources\Servers\Pages\CustomListServers;
use PelicanDev\ServerExpiry\Support\Expiry;
use Throwable;

class ServerExpiryPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'server-expiry';
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'server') {
            // Client-side "Expiration" page for each server (owner-only).
            $panel->discoverPages(
                plugin_path($this->getId(), 'src/Filament/Server/Pages'),
                'PelicanDev\ServerExpiry\Filament\Server\Pages',
            );

            return;
        }

        if ($panel->getId() !== 'admin') {
            return;
        }

        // Add an "Expiration" tab to the server edit form.
        //
        // Pelican's EditServer page renders the Tabs container in a responsive
        // grid (2/4/6 columns) which the tab content inherits. Setting an
        // explicit `columns()` on the tab overrides that inherited grid, and
        // `columnSpanFull()` makes the section card span the full width.
        EditServer::registerCustomTabs(
            TabPosition::After,
            Tab::make('expiration')
                ->label(trans('server-expiry::strings.tab_label'))
                ->icon(TablerIcon::Hourglass)
                ->columns([
                    'default' => 1,
                    'sm' => 2,
                    'lg' => 3,
                ])
                ->schema([
                    Section::make(trans('server-expiry::strings.section_title'))
                        ->description(trans('server-expiry::strings.section_description'))
                        ->columnSpanFull()
                        ->columns([
                            'default' => 1,
                            'lg' => 2,
                        ])
                        ->schema([
                            DateTimePicker::make('expires_at')
                                ->label(trans('server-expiry::strings.field_label'))
                                ->placeholder(trans('server-expiry::strings.field_placeholder'))
                                ->helperText(trans('server-expiry::strings.field_helper'))
                                ->nullable()
                                ->seconds(false)
                                ->native(false)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, Server $record) {
                                    // Renewal: if the server was suspended because it
                                    // expired and a new (future) date - or none - is set,
                                    // revive it and reset the warning thresholds so
                                    // reminders start over for the new date.
                                    if (! blank($state) && strtotime($state) <= time()) {
                                        return;
                                    }

                                    $record->forceFill(['expiry_warning_day' => null])->saveQuietly();

                                    if (! Expiry::isExpirySuspended($record)) {
                                        return;
                                    }

                                    try {
                                        app(SuspensionService::class)->handle($record, SuspendAction::Unsuspend);
                                    } catch (Throwable $exception) {
                                        report($exception);

                                        Notification::make()
                                            ->title(trans('server-expiry::strings.revival_failed'))
                                            ->body($exception->getMessage())
                                            ->danger()
                                            ->send();

                                        return;
                                    }

                                    Notification::make()
                                        ->title(trans('server-expiry::strings.revived'))
                                        ->success()
                                        ->send();
                                })
                                ->columnSpanFull(),
                            Placeholder::make('expiry_status')
                                ->label(trans('server-expiry::strings.status_label'))
                                ->content(fn (?Server $record): string => Expiry::statusText($record))
                                ->columnSpanFull(),
                        ])
                        ->collapsible(),
                ]),
        );

        // Add an "Expiration" step to the server creation wizard.
        CreateServer::registerCustomSteps(
            StepPosition::After,
            Step::make('expiration')
                ->label(trans('server-expiry::strings.tab_label'))
                ->icon(TablerIcon::Hourglass)
                ->schema([
                    Section::make(trans('server-expiry::strings.section_title'))
                        ->description(trans('server-expiry::strings.section_description'))
                        ->columnSpanFull()
                        ->columns([
                            'default' => 1,
                            'lg' => 2,
                        ])
                        ->schema([
                            DateTimePicker::make('expires_at')
                                ->label(trans('server-expiry::strings.field_label'))
                                ->placeholder(trans('server-expiry::strings.field_placeholder'))
                                ->helperText(trans('server-expiry::strings.field_helper'))
                                ->nullable()
                                ->seconds(false)
                                ->native(false)
                                ->columnSpanFull(),
                        ])
                        ->collapsible(),
                ]),
        );

        // Replace the server list page with an extension that adds an
        // "Expires At" status column (color-coded badges). This is done through
        // the sanctioned registerCustomPages hook; the custom page extends the
        // core page so all core behavior is preserved.
        ServerResource::registerCustomPages([
            'index' => CustomListServers::route('/'),
        ]);
    }

    public function boot(Panel $panel): void
    {
        // The custom expiry-suspension banner is registered via a render hook
        // in ServerExpiryServiceProvider::boot().
    }

    public function getSettingsFormData(): array
    {
        $data = config('server-expiry');

        // The form uses a comma-separated string for the warning thresholds.
        $data['warning_days_notice'] = implode(',', (array) $data['warning_days_notice']);

        return $data;
    }

    public function getSettingsForm(): array
    {
        return [
            Toggle::make('auto_suspend_enabled')
                ->label(trans('server-expiry::strings.settings_auto_suspend_label'))
                ->helperText(trans('server-expiry::strings.settings_auto_suspend_helper'))
                ->inline(false)
                ->default(fn () => config('server-expiry.auto_suspend_enabled')),
            TextInput::make('grace_period_hours')
                ->label(trans('server-expiry::strings.settings_grace_label'))
                ->helperText(trans('server-expiry::strings.settings_grace_helper'))
                ->numeric()
                ->minValue(0)
                ->default(fn () => config('server-expiry.grace_period_hours')),
            TextInput::make('warning_days_notice')
                ->label(trans('server-expiry::strings.settings_warning_label'))
                ->helperText(trans('server-expiry::strings.settings_warning_helper'))
                ->placeholder('7,3,1')
                ->rules(['regex:/^\s*\d+\s*(,\s*\d+\s*)*$/'])
                ->default(fn () => implode(',', config('server-expiry.warning_days_notice'))),
            Toggle::make('notify_owner_on_suspend')
                ->label(trans('server-expiry::strings.settings_notify_label'))
                ->helperText(trans('server-expiry::strings.settings_notify_helper'))
                ->inline(false)
                ->default(fn () => config('server-expiry.notify_owner_on_suspend')),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'SERVER_EXPIRY_AUTO_SUSPEND' => $data['auto_suspend_enabled'],
            'SERVER_EXPIRY_GRACE_HOURS' => (int) $data['grace_period_hours'],
            'SERVER_EXPIRY_WARNING_DAYS' => $data['warning_days_notice'],
            'SERVER_EXPIRY_NOTIFY_ON_SUSPEND' => $data['notify_owner_on_suspend'],
        ]);

        Notification::make()
            ->title(trans('server-expiry::strings.settings_saved'))
            ->success()
            ->send();
    }
}
