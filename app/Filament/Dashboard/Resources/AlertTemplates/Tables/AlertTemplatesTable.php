<?php

namespace App\Filament\Dashboard\Resources\AlertTemplates\Tables;

use App\Models\AlertTemplate;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AlertTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => $state->label()),
                TextColumn::make('sections')
                    ->label('Sections')
                    ->formatStateUsing(fn (AlertTemplate $record): string => $record->sections === null || $record->sections === []
                        ? '—'
                        : count($record->sections).' selected'),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
