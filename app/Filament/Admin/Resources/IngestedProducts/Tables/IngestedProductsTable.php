<?php

namespace App\Filament\Admin\Resources\IngestedProducts\Tables;

use App\Actions\Pipeline\ReplayIngestedProduct;
use App\Filament\Admin\Resources\TestProductScenarios\TestProductScenarioResource;
use App\Models\IngestedProduct;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

class IngestedProductsTable
{
    private const REPLAY_LIMIT = 25;

    private const REPLAY_WARNING = 'This re-sends the product through the alert system as a test copy. Only test accounts receive it, and it creates duplicate alerts.';

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('scenario'))
            ->columns([
                TextColumn::make('cccc')
                    ->label('Office')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (?string $state): string => $state ?? '—'),
                TextColumn::make('ttaaii')
                    ->label('TTAAII')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('awipsid')
                    ->label('AWIPS ID')
                    ->searchable(),
                TextColumn::make('source')
                    ->sortable(),
                TextColumn::make('wmo_ddhhmm')
                    ->label('Issued (DDHHMM)')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (?string $state): string => $state ?? '—'),
                TextColumn::make('received_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('scenario')
                    ->label('Test scenario')
                    ->state(fn (IngestedProduct $record): ?string => $record->is_test && TestProductScenarioResource::canAccess()
                        ? ($record->scenario?->name ?? ($record->test_product_scenario_id !== null ? "Scenario #{$record->test_product_scenario_id}" : null))
                        : null)
                    ->url(fn (IngestedProduct $record): ?string => $record->is_test && $record->test_product_scenario_id !== null && TestProductScenarioResource::canAccess()
                        ? TestProductScenarioResource::getUrl('edit', ['record' => $record->test_product_scenario_id])
                        : null),
            ])
            ->defaultSort('received_at', 'desc')
            ->filters([
                TernaryFilter::make('is_test')
                    ->label('Test products')
                    ->trueLabel('Test only')
                    ->falseLabel('Real only'),
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
                Action::make('replay')
                    ->label('Replay')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (): bool => ReplayIngestedProduct::allowed())
                    ->requiresConfirmation()
                    ->modalHeading('Replay this product?')
                    ->modalDescription(self::REPLAY_WARNING)
                    ->action(fn (IngestedProduct $record) => self::replay(collect([$record]))),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('replay')
                        ->label('Replay')
                        ->icon('heroicon-o-arrow-path')
                        ->visible(fn (): bool => ReplayIngestedProduct::allowed())
                        ->requiresConfirmation()
                        ->modalHeading('Replay the selected products?')
                        ->modalDescription(self::REPLAY_WARNING)
                        ->deselectRecordsAfterCompletion()
                        ->action(fn (Collection $records) => self::replay($records)),
                ]),
            ]);
    }

    /**
     * @param  Collection<int, IngestedProduct>  $records
     */
    private static function replay(Collection $records): void
    {
        if ($records->count() > self::REPLAY_LIMIT) {
            Notification::make()
                ->title('Select '.self::REPLAY_LIMIT.' or fewer products to replay')
                ->warning()
                ->send();

            return;
        }

        $replayed = 0;
        $failed = 0;

        foreach ($records->sortBy([['received_at', 'asc'], ['id', 'asc']]) as $product) {
            try {
                app(ReplayIngestedProduct::class)($product);
                $replayed++;
            } catch (Throwable $e) {
                $failed++;
                report($e);
            }
        }

        $products = Str::plural('product', $replayed);

        $notification = Notification::make();

        if ($failed === 0) {
            $notification->title("Replayed {$replayed} {$products} to test accounts")->success();
        } else {
            $notification->title("Replayed {$replayed} {$products}; {$failed} could not be replayed")->warning();
        }

        $notification->send();
    }
}
