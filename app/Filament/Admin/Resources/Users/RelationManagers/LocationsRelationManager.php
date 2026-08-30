<?php

namespace App\Filament\Admin\Resources\Users\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'locations';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                TextColumn::make('label'),
                TextColumn::make('type'),
                TextColumn::make('address'),
                TextColumn::make('created_at')->dateTime(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
