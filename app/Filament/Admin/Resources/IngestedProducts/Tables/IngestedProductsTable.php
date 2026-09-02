<?php

namespace App\Filament\Admin\Resources\IngestedProducts\Tables;

use App\Models\IngestedProduct;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IngestedProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cccc')
                    ->label('Office')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): string => $state ?? '—'),
                TextColumn::make('ttaaii')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('awipsid')
                    ->label('AWIPS ID')
                    ->searchable(),
                TextColumn::make('wmo_ddhhmm')
                    ->label('Issued (DDHHMM)')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (?string $state): string => $state ?? '—'),
                TextColumn::make('received_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('received_at', 'desc')
            ->filters([
                SelectFilter::make('cccc')
                    ->label('Office')
                    ->options(fn (): array => IngestedProduct::query()
                        ->whereNotNull('cccc')
                        ->distinct()
                        ->orderBy('cccc')
                        ->pluck('cccc', 'cccc')
                        ->all()),
                Filter::make('received_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('received_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('received_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
