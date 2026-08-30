<?php

namespace App\Filament\Admin\Resources\ActivityAlertRules\Tables;

use App\Enums\ActivityAlertScope;
use App\Models\ActivityAlertRule;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ActivityAlertRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->placeholder('—'),
                TextColumn::make('scope')
                    ->badge()
                    ->formatStateUsing(fn (ActivityAlertScope $state): string => $state->label()),
                TextColumn::make('activity_types')
                    ->label('Activity types')
                    ->getStateUsing(fn (ActivityAlertRule $record): string => count($record->activity_types).' selected'),
                IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
