<?php

namespace App\Filament\Admin\Resources\Users\RelationManagers;

use App\Enums\AlertCategory;
use App\Models\AlertRule;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AlertRulesRelationManager extends RelationManager
{
    protected static string $relationship = 'alertRules';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('displayName')
                    ->label('Alert')
                    ->getStateUsing(fn (AlertRule $record): string => $record->displayName()),
                TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn (AlertCategory $state): string => $state->label()),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('created_at')->dateTime(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
