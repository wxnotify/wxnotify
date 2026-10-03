<?php

namespace App\Filament\Dashboard\Resources\TraccarServers\Tables;

use App\Models\TraccarServer;
use App\Support\LocationDeletionGuard;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class TraccarServersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('base_url')
                    ->label('Server URL')
                    ->searchable(),
                TextColumn::make('last_polled_at')
                    ->label('Last polled')
                    ->since()
                    ->placeholder('Never'),
                TextColumn::make('last_poll_error')
                    ->label('Polling Status')
                    // Filament's TextColumn checks blank($state) on the raw
                    // database value before formatStateUsing() ever runs --
                    // a formatStateUsing(fn ($state) => $state ?? 'OK')
                    // closure is never reached for a null last_poll_error,
                    // so a healthy server rendered as a genuinely blank
                    // cell, not "OK". ->placeholder() is Filament's actual
                    // mechanism for this case: it's what renders when the
                    // early blank-state check short-circuits.
                    ->placeholder('OK')
                    ->badge()
                    ->color('danger'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::guardedDeleteBulkAction(),
                ]),
            ]);
    }

    /**
     * Deleting a server deletes its mobile locations through the database, so
     * it is refused while an alert still uses any of them (see
     * LocationDeletionGuard). Used by the Edit page's header action.
     */
    public static function guardedDeleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->before(function (DeleteAction $action, TraccarServer $record): void {
                $alerts = LocationDeletionGuard::alertsUsingServer($record);

                if ($alerts->isEmpty()) {
                    return;
                }

                Notification::make()
                    ->danger()
                    ->persistent()
                    ->title("Can't delete {$record->label}")
                    ->body('Its locations are used by: '.LocationDeletionGuard::summary($alerts).'. Remove them from those alerts first.')
                    ->send();

                $action->cancel();
            });
    }

    public static function guardedDeleteBulkAction(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->using(function (DeleteBulkAction $action, Collection $records): void {
                foreach ($records as $record) {
                    $alerts = LocationDeletionGuard::alertsUsingServer($record);

                    if ($alerts->isNotEmpty()) {
                        $action->reportBulkProcessingFailure(
                            key: "in-use-{$record->id}",
                            message: e($record->label).' has locations used by: '.LocationDeletionGuard::summary($alerts).'.',
                        );

                        continue;
                    }

                    $record->delete() || $action->reportBulkProcessingFailure();
                }
            });
    }
}
