<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookEndpoint;

use BackedEnum;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Actions;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Models\WebhookEndpoint;

class WebhookEndpointResource extends Resource
{
    protected static ?string $model = WebhookEndpoint::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|UnitEnum|null $navigationGroup = 'Server Expiry';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Endpoint Information')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('url')
                            ->label('URL')
                            ->required()
                            ->url()
                            ->maxLength(255)
                            ->rule('regex:/^(https?:\/\/)?(([a-zA-Z0-9\-]+\.)+[a-zA-Z]{2,}|(\d{1,3}\.){3}\d{1,3})(:\d+)?(\/.*)?$/i')
                            ->rule(function ($attribute, $value, $fail) {
                                // SSRF protection: block private, reserved and
                                // link-local addresses (covers IPv4 and IPv6,
                                // including loopback ::1 and 169.254.0.0/16).
                                if (! is_string($value) || $value === '') {
                                    return;
                                }
                                $ip = null;
                                if (preg_match('/^https?:\/\/([^\/@]+)/i', $value, $matches)) {
                                    $host = $matches[1];

                                    // Strip port if present (but not from IPv6 literals)
                                    if (strpos($host, ':') !== false && strpos($host, '[') === false) {
                                        $host = explode(':', $host)[0];
                                    }
                                    $host = trim($host, '[]');

                                    if (filter_var($host, FILTER_VALIDATE_IP)) {
                                        $ip = $host;
                                    } elseif ($host !== '' && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                                        $resolved = @gethostbyname($host);
                                        if ($resolved && $resolved !== $host) {
                                            $ip = $resolved;
                                        }
                                    }
                                }

                                if ($ip !== false && $ip !== null &&
                                    ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                                    $fail('The URL cannot point to a private or internal network address.');
                                }
                            }),
                        Forms\Components\Toggle::make('active')
                            ->label('Active')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('Security')
                    ->schema([
                        Forms\Components\TextInput::make('secret_key')
                            ->label('Secret Key')
                            ->password()
                            ->placeholder('Leave empty to keep the current secret')
                            ->dehydrated(fn ($state) => filled($state))
                            ->helperText('If left empty, the existing secret is kept unchanged.'),
                        Forms\Components\Placeholder::make('secret_key_preview')
                            ->label('Secret Key (Preview)')
                            ->content(fn (?WebhookEndpoint $record): ?string => filled($record?->secret_key)
                                ? str_repeat('*', min(8, strlen($record->secret_key)))
                                : null),
                    ])
                    ->columns(2),
                Section::make('Event Subscriptions')
                    ->schema([
                        Forms\Components\CheckboxList::make('events')
                            ->label('Subscribed Events')
                            ->options([
                                'server.expiry.created' => 'Expiration Created',
                                'server.expiry.updated' => 'Expiration Updated',
                                'server.expiry.warning' => 'Expiration Warning',
                                'server.expiry.expired' => 'Server Expired',
                                'server.expiry.suspended' => 'Server Suspended by Expiration',
                                'server.expiry.renewed' => 'Server Renewed',
                                'server.expiry.cleared' => 'Expiration Cleared',
                            ])
                            ->required()
                            ->helperText('Select which expiration events should trigger this webhook.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('url')
                    ->label('URL')
                    ->searchable()
                    ->sortable()
                    ->limit(50),
                Tables\Columns\IconColumn::make('active')
                    ->label('Status')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('event_count')
                    ->label('Event Subscriptions')
                    ->getStateUsing(function (WebhookEndpoint $record) {
                        return count($record->events ?? []);
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('failure_count')
                    ->label('Failures')
                    ->badge()
                    ->color('danger')
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_success_at')
                    ->label('Last Success')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('last_failed_at')
                    ->label('Last Failure')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Actions\ViewAction::make(),
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWebhookEndpoints::route('/'),
            'create' => Pages\CreateWebhookEndpoint::route('/create'),
            'edit' => Pages\EditWebhookEndpoint::route('/{record}/edit'),
            'view' => Pages\ViewWebhookEndpoint::route('/{record}/view'),
        ];
    }
}