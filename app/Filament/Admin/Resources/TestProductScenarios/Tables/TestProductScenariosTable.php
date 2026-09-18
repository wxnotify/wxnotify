<?php

namespace App\Filament\Admin\Resources\TestProductScenarios\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TestProductScenariosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->placeholder('Untitled scenario'),
                TextColumn::make('product.name')
                    ->label('Product'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ]);
    }
}
