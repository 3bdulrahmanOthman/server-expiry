<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookEndpoint;

use App\Filament\Admin\Resources\WebhookEndpoint\WebhookEndpointResource as BaseResource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Models\WebhookEndpoint;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Models\WebhookDelivery;
use SquadronStrike\ServerExpiry\Support\Expiry;

class WebhookEndpointResource extends Resource
{
    protected static string $model = WebhookEndpoint::class;

    protected static string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Server Expiry';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Endpoint Information')
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
                                // SSRF protection: block private IP addresses and localhost
                                $ip = null;
                                if (preg_match('/^https?:\/\/([^\/]+)/i', $value, $matches)) {
                                    $host = $matches[1];

                                    // Extract port if present
                                    if (strpos($host, ':') !== false) {
                                        list($host, $port) = explode(':', $host);
                                    }

                                    // Check if it's an IP address
                                    if (filter_var($host, FILTER_VALIDATE_IP)) {
                                        $ip = $host;
                                    } else {
                                        // Resolve hostname to IP
                                        $resolved = @gethostbyname(@gethostbyname($host));
                                        if ($resolved && $resolved !== $host) {
                                            $ip = $resolved;
                                        }
                                    }
                                }

                                if ($ip) {
                                    // Block private IP ranges and localhost
                                    if (preg_match('/^127\./', $ip) ||
                                        preg_match('/^10\./', $ip) ||
                                        preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $ip) ||
                                        preg_match('/^192\.168\./', $ip) ||
                                        $ip === '0.0.0.0' ||
                                        $ip === 'localhost') {
                                        $fail('The URL cannot point to a private or internal network address.');
                                    }
                                }
                            }),
                        Forms\Components\Toggle::make('active')
                            ->label('Active')
                            ->default(true),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Security')
                    ->schema([
                        Forms\Components\TextInput::make('secret_key')
                            ->label('Secret Key')
                            ->password()
                            ->placeholder('Leave empty to generate a new secret')
                            ->dehydrated(fn ($state) => filled($state))
                            ->hydrate(fn ($state) => filled($state) ? $state : null)
                            ->helperText('If left empty, a new secret will be generated upon saving.'),
                        Forms\Components\Placeholder::make('secret_key_preview')
                            ->label('Secret Key (Preview)')
                            ->content(fn (WebhookEndpoint $record): ?string => $record->secret_key ? str_repeat('*', min(8, strlen($record->secret_key))) : null),
                    ])
                    ->columns(2),
                Forms\Components\Section::make('Event Subscriptions')
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
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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