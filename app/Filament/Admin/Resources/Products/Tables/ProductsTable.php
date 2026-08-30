<?php

namespace App\Filament\Admin\Resources\Products\Tables;

use App\Actions\Catalog\RetireProduct;
use App\Enums\ProductFamily;
use App\Enums\ProductStatus;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('family')
                    ->badge()
                    ->formatStateUsing(fn (ProductFamily $state): string => $state->label()),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (ProductStatus $state): string => match ($state) {
                        ProductStatus::Draft => 'gray',
                        ProductStatus::Active => 'success',
                        ProductStatus::Retired => 'danger',
                    }),
                IconColumn::make('has_polygon')
                    ->boolean(),
                TextColumn::make('sort_order'),
            ])
            ->reorderable('sort_order')
            ->filters([
                SelectFilter::make('family')
                    ->options(collect(ProductFamily::cases())->mapWithKeys(fn (ProductFamily $f) => [$f->value => $f->label()])),
                SelectFilter::make('status')
                    ->options(collect(ProductStatus::cases())->mapWithKeys(fn (ProductStatus $s) => [$s->value => ucfirst($s->value)])),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('retire')
                    ->requiresConfirmation()
                    ->color('danger')
                    ->visible(fn (Product $record): bool => $record->status !== ProductStatus::Retired)
                    ->action(function (Product $record): void {
                        $count = app(RetireProduct::class)($record);

                        Notification::make()
                            ->title($count === 1 ? '1 alert rule disabled' : "{$count} alert rules disabled")
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
