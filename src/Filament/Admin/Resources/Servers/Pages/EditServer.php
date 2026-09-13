<?php

declare(strict_types=1);

namespace SquadronStrike\ServerExpiry\Filament\Admin\Resources\Servers\Pages;

use App\Filament\Admin\Resources\Servers\Pages\EditServer as PelicanEditServer;
use App\Models\Server;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use SquadronStrike\ServerExpiry\Application\Services\ExpirationService;
use SquadronStrike\ServerExpiry\Domain\Expiration\ValueObjects\ExpirationDate;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Plugin-owned custom Edit Server page for the Admin panel.
 * Extends Pelican's EditServer to add expiration management actions.
 */
class EditServer extends PelicanEditServer
{
    public function getHeaderActions(): array
    {
        return array_merge(parent::getHeaderActions(), [
            Action::make('set_expiration')
                ->label('Set Expiration')
                ->icon('heroicon-o-calendar-plus')
                ->color('primary')
                ->form([
                    DateTimePicker::make('expires_at')
                        ->label('Expiration Date')
                        ->placeholder('Select expiration date')
                        ->helperText('Set the date and time when the server will expire')
                        ->nullable()
                        ->seconds(false)
                        ->native(false)
                        ->rule('after_or_equal:today')
                ])
                ->action(function (array $data, Server $record) {
                    if (! Gate::allows('update', $record)) {
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
                        ->body('Expiration date set successfully.')
                        ->send();
                })
                ->requiresConfirmation()
                ->modalWidth('md')
                ->modalSubmitActionLabel('Set Expiration')
                ->modalCancelActionLabel('Cancel'),

            Action::make('extend')
                ->label('Extend +30 Days')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->action(function (Server $record) {
                    if (! Gate::allows('update', $record)) {
                        Notification::make()
                            ->danger()
                            ->body('You are not authorized to modify expiration for this server.')
                            ->send();
                        return;
                    }

                    $expirationService = app(ExpirationService::class);
                    $expirationService->extendExpiration($record->getKey(), new \DateInterval('P30D'));

                    Notification::make()
                        ->success()
                        ->body('Server expiration extended by 30 days.')
                        ->send();
                })
                ->requiresConfirmation()
                ->modalWidth('md')
                ->modalSubmitActionLabel('Extend')
                ->modalCancelActionLabel('Cancel'),

            Action::make('renew')
                ->label('Renew')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->action(function (Server $record) {
                    if (! Gate::allows('update', $record)) {
                        Notification::make()
                            ->danger()
                            ->body('You are not authorized to renew this server.')
                            ->send();
                        return;
                    }

                    $expirationService = app(ExpirationService::class);
                    $currentExpiration = $expirationService->getExpiration($record->getKey());
                    $now = new \DateTimeImmutable('now');

                    if ($currentExpiration->isPermanent()) {
                        $newDateTime = $now->add(new \DateInterval('P30D'));
                    } else {
                        $newDateTime = $currentExpiration->getDateTime()->add(new \DateInterval('P30D'));
                    }

                    $newExpiration = ExpirationDate::fromDateTime($newDateTime);
                    $expirationService->renew($record->getKey(), $newExpiration);

                    Notification::make()
                        ->success()
                        ->body('Server renewed successfully.')
                        ->send();
                })
                ->requiresConfirmation()
                ->modalWidth('md')
                ->modalSubmitActionLabel('Renew')
                ->modalCancelActionLabel('Cancel'),

            Action::make('clear')
                ->label('Clear Expiration')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->action(function (Server $record) {
                    if (! Gate::allows('update', $record)) {
                        Notification::make()
                            ->danger()
                            ->body('You are not authorized to clear expiration for this server.')
                            ->send();
                        return;
                    }

                    $expirationService = app(ExpirationService::class);
                    $expirationService->clearExpiration($record->getKey());

                    Notification::make()
                        ->success()
                        ->body('Expiration cleared successfully.')
                        ->send();
                })
                ->requiresConfirmation()
                ->modalWidth('md')
                ->modalSubmitActionLabel('Clear')
                ->modalCancelActionLabel('Cancel')
                ->destructive()
        ]);
    }
}