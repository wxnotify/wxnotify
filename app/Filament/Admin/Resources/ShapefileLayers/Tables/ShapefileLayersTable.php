<?php

namespace App\Filament\Admin\Resources\ShapefileLayers\Tables;

use App\Models\ShapefileLayer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ShapefileLayersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('activeVersion.effective_at')
                    ->label('Active version')
                    ->date()
                    ->placeholder('Never activated'),
                TextColumn::make('status')
                    ->label('Status')
                    ->state(fn (ShapefileLayer $record): string => $record->pendingVersion !== null ? 'pending' : 'up_to_date')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'pending' ? 'Pending review' : 'Up to date')
                    ->color(fn (string $state): string => $state === 'pending' ? 'warning' : 'success'),
            ])
            ->defaultSort('name');
    }
}
