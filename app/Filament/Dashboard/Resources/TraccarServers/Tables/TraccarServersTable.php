<?php

namespace App\Filament\Dashboard\Resources\TraccarServers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
