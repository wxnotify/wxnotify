<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Tables;

use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Models\AlertRule;
use App\Models\Product;
use App\Support\ProductMatchSettings;
use App\Support\Units;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AlertRulesTable
{
    /**
     * Dispatched by the table's inline toggle and delete actions so the page's
     * header widgets (stats, needs attention, active now) re-read the data.
     */
    public const CHANGED_EVENT = 'my-alerts-changed';

    private const NONE = 'None';

    private const MIXED = 'Mixed';

    private const US_WIDE = 'US-wide';

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['location', 'locations', 'notificationChannels', 'products', 'productLocations']))
            ->columns([
                TextColumn::make('displayName')
                    ->label('Alert')
                    ->getStateUsing(fn (AlertRule $record): string => $record->displayName(Units::for(auth()->user())->system))
                    ->searchable(query: fn ($query, string $search) => $query->where('name', 'like', "%{$search}%")),
                TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn (AlertCategory $state): string => $state->label())
                    ->icon(fn (AlertCategory $state): string => $state->icon()),
                TextColumn::make('location_labels')
                    ->label('Locations')
                    ->getStateUsing(fn (AlertRule $record): array => self::locationLabels($record))
                    ->badge()
                    ->color(fn (string $state): string => in_array($state, self::mutedLabels(), true) ? 'gray' : 'primary')
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->toggleable(),
                TextColumn::make('notificationChannels.label')
                    ->label('Channels')
                    ->badge()
                    ->placeholder(self::NONE)
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList()
                    ->toggleable(),
                ToggleColumn::make('is_active')
                    ->label('Active')
                    ->afterStateUpdated(fn (HasTable $livewire) => $livewire->dispatch(self::CHANGED_EVENT)),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options(collect(AlertCategory::cases())->mapWithKeys(fn (AlertCategory $c) => [$c->value => $c->label()])),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->after(fn (HasTable $livewire) => $livewire->dispatch(self::CHANGED_EVENT)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->after(fn (HasTable $livewire) => $livewire->dispatch(self::CHANGED_EVENT)),
                ]),
            ]);
    }

    /**
     * Where an alert applies: its saved locations (an outlook report's chosen ones, or US-wide when it
     * has none), or -- for matching modes
     * that are not tied to a saved location -- the mode's short name. A
     * Weather Alerts / SPC rule is read product by product: one shared mode
     * reads as before, differing modes read "Mixed" followed by the union of
     * the products' own locations.
     *
     * This compares the MODE only, as the spec asks for the list: products that share a mode but hold
     * different locations are still one mode and list the union of their locations. Do not unify it
     * with AlertRuleResource::matching() (the API), which compares the FULL settings so the mobile app
     * never mistakes a mixed rule for a uniform one.
     *
     * @return list<string>
     */
    private static function locationLabels(AlertRule $record): array
    {
        if ($record->category === AlertCategory::OutlookReport) {
            $labels = $record->locations->pluck('label')->values()->all();

            return $labels === [] ? [self::US_WIDE] : $labels;
        }

        if (! in_array($record->category, [AlertCategory::WeatherAlerts, AlertCategory::SpcWpc], true)) {
            $labels = array_filter([$record->location?->label]);

            return $labels === [] ? [self::NONE] : array_values($labels);
        }

        $settings = $record->products
            ->map(fn (Product $product): ?ProductMatchSettings => $record->settingsFor($product->id))
            ->filter()
            ->values();

        if ($settings->isEmpty()) {
            $settings = collect([new ProductMatchSettings($record->matching_mode, null, [], $record->locations, true)]);
        }

        $modes = $settings->map(fn (ProductMatchSettings $entry): ?MatchingMode => $entry->mode)->unique();
        $mode = $modes->count() === 1 ? $modes->first() : null;

        if (in_array($mode, [MatchingMode::Nationwide, MatchingMode::Wfo, MatchingMode::CountyZone], true)) {
            return [$mode->shortLabel()];
        }

        $labels = $settings
            ->flatMap(fn (ProductMatchSettings $entry): Collection => $entry->locations)
            ->unique('id')
            ->sortBy('id')
            ->pluck('label')
            ->values()
            ->all();

        if ($modes->count() > 1) {
            return [self::MIXED, ...$labels];
        }

        return $labels === [] ? [self::NONE] : $labels;
    }

    /**
     * @return list<string>
     */
    private static function mutedLabels(): array
    {
        return [
            self::NONE,
            self::MIXED,
            MatchingMode::Nationwide->shortLabel(),
            MatchingMode::Wfo->shortLabel(),
            MatchingMode::CountyZone->shortLabel(),
        ];
    }
}
