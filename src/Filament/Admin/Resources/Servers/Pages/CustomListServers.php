<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Filament\Admin\Resources\Servers\Pages;

use App\Filament\Admin\Resources\Servers\Pages\ListServers;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use DateInterval;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;
use SquadronStrike\ServerExpiry\Application\Services\ExpirationService;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\ExpirationDate;
use SquadronStrike\ServerExpiry\Support\Expiry;

/**
 * Extends Pelican's admin server list page and appends an "Expires At"
 * status column with color-coded badges:
 * - gray   = permanent (no expiration set)
 * - red    = expired
 * - yellow = expiring within the warning window (7 days by default, the
 *            largest SERVER_EXPIRY_WARNING_DAYS threshold)
 * - green  = active (expiry far in the future)
 *
 * Registered via ServerResource::registerCustomPages(['index' => CustomListServers::route('/')]).
 */
class CustomListServers extends ListServers
{
    public function table(Table $table): Table
    {
        $table = parent::table($table);

        $table->addAction(
            Action::make('extend')
                ->label(trans('server-expiry::strings.action_extend'))
                ->color('success')
                ->action(fn (Server $record) => app(ExpirationService::class)->extendExpiration(
                    $record->getKey(),
                    new DateInterval('P30D')
                ))
                ->requiresConfirmation()
                ->icon('heroicon-o-arrow-path')
                ->tooltip(trans('server-expiry::strings.action_extend_tooltip'))
        );

        $table->addAction(
            Action::make('renew')
                ->label(trans('server-expiry::strings.action_renew'))
                ->color('warning')
                ->action(function (Server $record) {
                    $expirationService = app(ExpirationService::class);
                    $currentExpiration = $expirationService->getExpiration($record->getKey());
                    $now = new DateTimeImmutable('now');
                    $newDateTime = $currentExpiration->isPermanent()
                        ? $now->add(new DateInterval('P30D'))
                        : $currentExpiration->getDateTime()->add(new DateInterval('P30D'));
                    $newExpiration = ExpirationDate::fromDateTime($newDateTime);
                    $expirationService->renew($record->getKey(), $newExpiration);
                })
                ->requiresConfirmation()
                ->icon('heroicon-o-arrow-path')
                ->tooltip(trans('server-expiry::strings.action_renew_tooltip'))
        );

        $table->addAction(
            Action::make('clear')
                ->label(trans('server-expiry::strings.action_clear'))
                ->color('danger')
                ->action(fn (Server $record) => app(ExpirationService::class)->clearExpiration($record->getKey()))
                ->requiresConfirmation()
                ->icon('heroicon-o-trash')
                ->tooltip(trans('server-expiry::strings.action_clear_tooltip'))
        );

        $table->addAction(
            Action::make('set_expiration')
                ->label(trans('server-expiry::strings.action_set_expiration'))
                ->color('primary')
                ->form([
                    Forms\Components\DateTimePicker::make('expires_at')
                        ->label(trans('server-expiry::strings.field_label'))
                        ->required()
                        ->native(false)
                        ->seconds(false),
                ])
                ->action(function (array $data, Server $record) {
                    app(ExpirationService::class)->setExpiration(
                        $record->getKey(),
                        ExpirationDate::fromDateTime(Carbon::parse($data['expires_at']))
                    );
                    Notification::make()
                        ->success()
                        ->body(trans('server-expiry::strings.settings_saved'))
                        ->send();
                })
                ->requiresConfirmation()
                ->icon('heroicon-o-calendar-plus')
                ->modalButton(trans('server-expiry::strings.action_set_expiration'))
                ->modalSubmitActionLabel(trans('server-expiry::strings.action_set_expiration'))
                ->modalCancelActionLabel('Cancel')
                ->modalWidth('lg')
        );

        $table->addAction(
            Action::make('check_status')
                ->label(trans('server-expiry::strings.action_check_status'))
                ->color('info')
                ->action(function (Server $record) {
                    $expirationService = app(ExpirationService::class);
                    $statusText = Expiry::statusText($record);
                    $remainingText = Expiry::remainingText($record);
                    $isExpired = $expirationService->isExpired($record->getKey());
                    $isInGracePeriod = $expirationService->isInGracePeriod($record->getKey());
                    $suspensionReason = $record->suspension_reason ?? 'none';

                    Notification::make()
                        ->info()
                        ->title(trans('server-expiry::strings.action_check_status') . ' #' . $record->id)
                        ->body(
                            trans('server-expiry::strings.status_label') . ": {$statusText}\n".
                            trans('server-expiry::strings.remaining_label') . ": {$remainingText}\n".
                            trans('server-expiry::strings.status_label') . " Expired: " . ($isExpired ? 'Yes' : 'No') . "\n".
                            trans('server-expiry::strings.status_label') . " In Grace Period: " . ($isInGracePeriod ? 'Yes' : 'No') . "\n".
                            trans('server-expiry::strings.suspension_reason_label') . ": {$suspensionReason}"
                        )
                        ->send();
                })
                ->icon('heroicon-o-information-circle')
        );

        $table->addBulkAction(
            BulkAction::make('renew')
                ->label(trans('server-expiry::strings.bulk_action_renew'))
                ->color('warning')
                ->action(function (Collection $records) {
                    $expirationService = app(ExpirationService::class);
                    foreach ($records as $record) {
                        $currentExpiration = $expirationService->getExpiration($record->getKey());
                        $now = new DateTimeImmutable('now');
                        $newDateTime = $currentExpiration->isPermanent()
                            ? $now->add(new DateInterval('P30D'))
                            : $currentExpiration->getDateTime()->add(new DateInterval('P30D'));
                        $newExpiration = ExpirationDate::fromDateTime($newDateTime);
                        $expirationService->renew($record->getKey(), $newExpiration);
                    }
                    Notification::make()
                        ->success()
                        ->body(trans('server-expiry::strings.settings_saved'))
                        ->send();
                })
                ->requiresConfirmation()
        );

        $table->addBulkAction(
            BulkAction::make('clear')
                ->label(trans('server-expiry::strings.bulk_action_clear'))
                ->color('danger')
                ->action(function (Collection $records) {
                    $expirationService = app(ExpirationService::class);
                    foreach ($records as $record) {
                        $expirationService->clearExpiration($record->getKey());
                    }
                    Notification::make()
                        ->success()
                        ->body(trans('server-expiry::strings.settings_saved'))
                        ->send();
                })
                ->requiresConfirmation()
        );

        return $table->columns([
            ...$table->getColumns(),
            TextColumn::make('expires_at')
                ->label(trans('server-expiry::strings.column_label'))
                ->dateTime('Y-m-d H:i')
                ->placeholder(trans('server-expiry::strings.column_permanent'))
                ->badge()
                ->sortable()
                ->toggleable()
                ->color(fn (Server $record): string => match (true) {
                    blank($record->expires_at) => 'gray',
                    now()->gte(Carbon::parse($record->expires_at)) => 'danger',
                    now()->addDays(Expiry::warningDays())->gte(Carbon::parse($record->expires_at)) => 'warning',
                    default => 'success',
                }),
            TextColumn::make('suspension_reason')
                ->label('Suspension Reason')
                ->formatStateUsing(fn ($state): string => ucfirst($state ?? 'none'))
                ->toggleable(isToggledHiddenByDefault: true)
                ->searchable(),
            TextColumn::make('remaining_time')
                ->label('Remaining Time')
                ->state(fn (Server $record): string => Expiry::remainingText($record))
                ->toggleable(isToggledHiddenByDefault: true)
                ->sortable(fn (Builder $query, string $direction) => $query->orderBy(
                    'expires_at',
                    $direction === 'desc' ? 'asc' : 'desc'
                )),
            TextColumn::make('grace_period')
                ->label('Grace Period')
                ->state(fn (Server $record): string => app(ExpirationService::class)->isInGracePeriod($record->getKey())
                    ? 'Yes'
                    : 'No')
                ->toggleable(isToggledHiddenByDefault: true)
                ->formatStateUsing(fn ($state): string => match ($state) {
                    'Yes' => '<span class="badge badge-success">Yes</span>',
                    'No' => '<span class="badge badge-danger">No</span>',
                })
                ->html(),
        ]);
    }
}
