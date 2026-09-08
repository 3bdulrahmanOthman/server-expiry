<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Filament\Admin\Resources\WebhookDelivery;

use App\Filament\Admin\Resources\WebhookDelivery\WebhookDeliveryResource as BaseResource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Notification;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Models\WebhookDelivery;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\Models\WebhookEndpoint;
use SquadronStrike\ServerExpiry\Infrastructure\Webhooks\WebhookDeliveryService;
use SquadronStrike\ServerExpiry\Support\Expiry;

class WebhookDeliveryResource extends Resource
{
    protected static string $model = WebhookDelivery::class;

    protected static string $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static ?string $navigationGroup = 'Server Expiry';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Delivery Information')
                    ->schema([
                        Forms\Components\TextInput::make('webhook_endpoint.name')
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
                Forms\Components\Section::make('Payload')
                    ->schema([
                        Forms\Components\Textarea::make('payload')
                            ->label('Payload')
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
                Tables\Columns\TextColumn::make('webhook_endpoint.name')
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
                Tables\Columns\IconColumn::make('status')
                    ->label('Status')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-x-mark')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                Tables\Filters\SelectFilter::make('webhook_endpoint.name')
                    ->label('Endpoint')
                    ->relationship('webhook_endpoint', 'name'),
                Tables\Filters\TernaryFilter::make('status')
                    ->label('Retryable')
                    ->trueIcon('heroicon-o-arrow-path')
                    ->falseIcon('heroicon-o-lock-closed')
                    ->trueQuery(fn (Builder $query) => $query->where('status', 'failed')
                        ->whereRaw('attempt < max_attempts'))
                    ->falseQuery(fn (Builder $query) => $query->where(function (Builder $query) {
                        $query->where('status', '!=', 'failed')
                            ->orWhereRaw('attempt >= max_attempts');
                    })),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('retry')
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
            'index' => Pages\ListWebhookDeliveries::route('/'),
            'create' => Pages\CreateWebhookDelivery::route('/create'),
            'edit' => Pages\EditWebhookDelivery::route('/{record}/edit'),
            'view' => Pages\ViewWebhookDelivery::route('/{record}/view'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('webhookEndpoint');
    }
}