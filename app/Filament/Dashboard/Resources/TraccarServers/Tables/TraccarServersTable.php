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
                    ->formatStateUsing(fn (?string $state): string => $state ?? 'OK')
                    ->badge()
                    ->color(fn (?string $state): string => $state === null ? 'success' : 'danger'),
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
