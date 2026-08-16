<?php

namespace PelicanDev\ServerExpiry\Filament\Admin\Resources\Servers\Pages;

use App\Models\Server;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use PelicanDev\ServerExpiry\Support\Expiry;

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
class CustomListServers extends \App\Filament\Admin\Resources\Servers\Pages\ListServers
{
    public function table(Table $table): Table
    {
        $table = parent::table($table);

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
        ]);
    }
}
