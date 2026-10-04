<?php

namespace App\Filament\Dashboard\Resources\Locations\Tables;

use App\Models\Location;
use App\Support\LocationDeletionGuard;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class LocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('position_updated')
                    ->label('Position updated')
                    ->state(fn (Location $record) => $record->positionLocatedAt())
                    ->since()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('address')
                    ->limit(50)
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                self::guardedDeleteAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::guardedDeleteBulkAction(),
                ]),
            ]);
    }

    /**
     * Refuses to delete a location an alert still uses (it would leave that
     * alert with no location and silently stop it from ever firing). Shared
     * by the table's row action and the Edit page's header action.
     */
    public static function guardedDeleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->before(function (DeleteAction $action, Location $record): void {
                $alerts = LocationDeletionGuard::alertsUsing($record);

                if ($alerts->isEmpty()) {
                    return;
                }

                Notification::make()
                    ->danger()
                    ->persistent()
                    ->title("Can't delete {$record->label}")
                    ->body('It\'s used by: '.LocationDeletionGuard::summary($alerts).'. Remove it from those alerts first.')
                    ->send();

                $action->cancel();
            });
    }

    /**
     * Deletes the unused locations in the selection and reports each one an
     * alert still uses, through Filament's own partial-failure notification.
     */
    public static function guardedDeleteBulkAction(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->using(function (DeleteBulkAction $action, Collection $records): void {
                foreach ($records as $record) {
                    $alerts = LocationDeletionGuard::alertsUsing($record);

                    if ($alerts->isNotEmpty()) {
                        $action->reportBulkProcessingFailure(
                            key: "in-use-{$record->id}",
                            message: e($record->label).' is used by: '.LocationDeletionGuard::summary($alerts).'.',
                        );

                        continue;
                    }

                    $record->delete() || $action->reportBulkProcessingFailure();
                }
            });
    }
}
