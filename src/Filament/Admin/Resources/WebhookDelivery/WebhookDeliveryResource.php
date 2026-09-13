<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookDelivery;

use BackedEnum;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Actions;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Models\WebhookDelivery;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\WebhookDeliveryService;

class WebhookDeliveryResource extends Resource
{
    protected static ?string $model = WebhookDelivery::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static string|UnitEnum|null $navigationGroup = 'Server Expiry';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Delivery Information')
                    ->schema([
                        Forms\Components\TextInput::make('endpoint.name')
                            ->label('Webhook Endpoint')
                            ->readOnly()
                            ->disabled(),
                        Forms\Components\TextInput::make('event_type')
                            ->label('Event Type')
                            ->readOnly()
                            ->disabled(),
                        Forms\Components\TextInput::make('server_id')
                            ->label('Server ID')
                            ->readOnly()
                            ->disabled(),
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                'pending' => 'Pending',
                                'success' => 'Success',
                                'failed' => 'Failed',
                            ])
                            ->readOnly()
                            ->disabled(),
                        Forms\Components\TextInput::make('attempt')
                            ->label('Attempt')
                            ->readOnly()
                            ->disabled(),
                        Forms\Components\TextInput::make('max_attempts')
                            ->label('Max Attempts')
                            ->readOnly()
                            ->disabled(),
                        Forms\Components\TextInput::make('http_status_code')
                            ->label('HTTP Status Code')
                            ->readOnly()
                            ->disabled(),
                        Forms\Components\Textarea::make('error_message')
                            ->label('Error Message')
                            ->readOnly()
                            ->disabled()
                            ->columnSpanFull(),
                        Forms\Components\DateTimePicker::make('queued_at')
                            ->label('Queued At')
                            ->readOnly()
                            ->disabled(),
                        Forms\Components\DateTimePicker::make('processed_at')
                            ->label('Processed At')
                            ->readOnly()
                            ->disabled(),
                        Forms\Components\DateTimePicker::make('next_attempt_at')
                            ->label('Next Attempt At')
                            ->readOnly()
                            ->disabled(),
                    ])
                    ->columns(2),
                Section::make('Payload')
                    ->schema([
                        Forms\Components\Textarea::make('payload')
                            ->label('Payload')
                            ->formatStateUsing(fn ($state) => is_array($state) ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $state)
                            ->readOnly()
                            ->disabled()
                            ->columnSpanFull()
                            ->rows(10),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('endpoint.name')
                    ->label('Endpoint')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('event_type')
                    ->label('Event Type')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('server_id')
                    ->label('Server ID')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'success' => 'success',
                        'failed' => 'danger',
                        default => 'warning',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('attempt')
                    ->label('Attempt')
                    ->sortable(),
                Tables\Columns\TextColumn::make('http_status_code')
                    ->label('HTTP Status')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('queued_at')
                    ->label('Queued')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('processed_at')
                    ->label('Processed')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('next_attempt_at')
                    ->label('Next Attempt')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending',
                        'success' => 'Success',
                        'failed' => 'Failed',
                    ]),
                Tables\Filters\SelectFilter::make('endpoint.name')
                    ->label('Endpoint')
                    ->relationship('endpoint', 'name'),
                Tables\Filters\TernaryFilter::make('retryable')
                    ->label('Retryable')
                    ->queries(
                        fn (Builder $query) => $query->where('status', 'failed')
                            ->whereRaw('attempt < max_attempts'),
                        fn (Builder $query) => $query->where(function (Builder $query) {
                            $query->where('status', '!=', 'failed')
                                ->orWhereRaw('attempt >= max_attempts');
                        }),
                    ),
            ])
            ->actions([
                Actions\ViewAction::make(),
                Actions\Action::make('retry')
                    ->label('Retry')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (WebhookDelivery $record) {
                        if ($record->canRetry()) {
                            // Reset delivery for retry attempt
                            $record->update([
                                'status' => 'pending',
                                'next_attempt_at' => now(),
                                'processed_at' => null,
                                'error_message' => null,
                            ]);

                            // Attempt delivery
                            app(WebhookDeliveryService::class)->attemptDelivery($record);

                            // Show notification
                            Notification::make()
                                ->success()
                                ->body('Webhook delivery queued for retry')
                                ->send();
                        } else {
                            Notification::make()
                                ->error()
                                ->body('This delivery cannot be retried')
                                ->send();
                        }
                    })
                    ->visible(fn (WebhookDelivery $record) => $record->canRetry()),
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
            'index' => Pages\ListWebhookDeliveries::route('/'),
            'create' => Pages\CreateWebhookDelivery::route('/create'),
            'edit' => Pages\EditWebhookDelivery::route('/{record}/edit'),
            'view' => Pages\ViewWebhookDelivery::route('/{record}/view'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('endpoint');
    }
}