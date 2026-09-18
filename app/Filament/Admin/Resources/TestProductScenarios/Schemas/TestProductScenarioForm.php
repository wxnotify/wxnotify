<?php

namespace App\Filament\Admin\Resources\TestProductScenarios\Schemas;

use App\Models\ActiveWwa;
use App\Models\Product;
use App\Models\TestProductScenario;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class TestProductScenarioForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(null)
            ->components([
                Wizard::make(self::steps())->contained(false),
            ]);
    }

    /**
     * @return array<Step>
     */
    public static function steps(): array
    {
        return [
            self::productAndTimingStep(),
            Step::make('Geography'),
            Step::make('Content'),
            Step::make('Review & Generate'),
        ];
    }

    /**
     * Known v1 simplification: a real CON/EXP/CAN continuation is issued as
     * its own distinct product (e.g. an "SVS" Severe Weather Statement,
     * not another "SV.W" Severe Thunderstorm Warning) with its own MND
     * header wording and bullet shape. The "Continue" flow here instead
     * re-renders the ORIGINAL event's product (inherited via
     * inheritFromContinuedScenario() below) through the same warning
     * template, just with vtec_action changed -- close enough to exercise
     * the VTEC lifecycle (NEW -> CON -> EXP/CAN) and matching/dispatch
     * logic end to end, but the raw text's own product name/wording won't
     * read as a textbook-accurate SVS. Revisit with a dedicated
     * continuation template if that precision is ever needed.
     */
    protected static function productAndTimingStep(): Step
    {
        return Step::make('Product & Timing')
            ->schema([
                Radio::make('mode')
                    ->label('')
                    ->options(['new' => 'New event', 'continue' => 'Continue existing event'])
                    ->default('new')
                    ->live()
                    ->visible(fn (): bool => self::openSyntheticEventOptions() !== []),
                Select::make('continues_scenario_id')
                    ->label('Continue which event?')
                    ->options(fn (): array => self::openSyntheticEventOptions())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set, Get $get) => self::inheritFromContinuedScenario($set, $get))
                    ->visible(fn (Get $get): bool => $get('mode') === 'continue'),
                Select::make('input.vtec_action')
                    ->label('Action')
                    ->options(['CON' => 'Continue (CON)', 'EXP' => 'Expire (EXP)', 'CAN' => 'Cancel (CAN)'])
                    ->required()
                    ->visible(fn (Get $get): bool => $get('mode') === 'continue'),
                Select::make('product_id')
                    ->label('Product')
                    ->options(fn (): array => self::eligibleProductsQuery()
                        ->orderBy('sort_order')->get()
                        ->groupBy(fn (Product $p): string => $p->family->label())
                        ->map(fn ($products) => $products->pluck('name', 'id')->all())
                        ->all())
                    ->required()
                    ->live()
                    ->visible(fn (Get $get): bool => $get('mode') !== 'continue'),
                Select::make('input.wfo')
                    ->label('NWS office (WFO)')
                    ->searchable()
                    ->required()
                    ->options(fn (): array => DB::table('cwa_boundaries')->orderBy('cwa')->get(['cwa', 'citystate'])
                        ->mapWithKeys(fn ($row): array => [$row->cwa => "{$row->cwa} — {$row->citystate}"])
                        ->all())
                    ->visible(fn (Get $get): bool => $get('mode') !== 'continue'),
                DateTimePicker::make('input.issued_at')
                    ->label('Issuance time')
                    ->default(now())
                    ->seconds(false)
                    ->required(),
                TextInput::make('input.expires_minutes')
                    ->label('Expires in (minutes)')
                    ->numeric()
                    ->minValue(1)
                    ->default(60)
                    ->required()
                    ->visible(fn (Get $get): bool => self::selectedProductHasVtec($get)),
            ]);
    }

    /**
     * v1-eligible products: warning/watch/advisory (VTEC), spc_wpc
     * restricted to has_polygon (MD/MPD -- excludes the shapefile-based
     * Outlook family, which shares the same family value but is out of
     * scope), forecast_discussion (AFD), and SPS specifically (the one
     * plain-text "statement" that stands on its own). SVS is deliberately
     * excluded here even though it's has_vtec -- a real SVS's VTEC
     * phenomenon/significance is always inherited from the warning it
     * continues, so it has no meaningful "New event" shape of its own; it's
     * only ever reachable through the "Continue existing event" flow
     * (inheritFromContinuedScenario() below), which is exactly how a real
     * SVS comes to exist. Every status, including draft, is included for
     * the eligible families -- this is the concrete use case draft status
     * was built for.
     */
    public static function eligibleProductsQuery(): Builder
    {
        return Product::query()
            ->where(function (Builder $q): void {
                $q->whereIn('family', ['warning', 'watch', 'advisory'])
                    ->orWhere(fn (Builder $q2) => $q2->where('family', 'spc_wpc')->where('has_polygon', true))
                    ->orWhere('family', 'forecast_discussion')
                    ->orWhere('code', 'SPS');
            });
    }

    /**
     * Keyed by TestProductScenario id (via each open event's most recent
     * IngestedProduct -> its originating scenario), NOT by ActiveWwa id --
     * test_product_scenarios.continues_scenario_id is a self-referencing FK
     * to test_product_scenarios, so the Select's stored value must be a
     * scenario id for a direct save to persist the right relationship.
     * Excludes any ActiveWwa row whose latest IngestedProduct has no
     * scenario link at all (defense-in-depth; every row created through
     * GenerateTestProduct always sets one).
     *
     * @return array<int, string>
     */
    public static function openSyntheticEventOptions(): array
    {
        return ActiveWwa::query()
            ->where('is_test', true)
            ->where('status', 'Active')
            ->whereHas('latestIngestedProduct', fn (Builder $q) => $q->whereNotNull('test_product_scenario_id'))
            ->with(['product', 'latestIngestedProduct'])
            ->latest('updated_at')
            ->get()
            ->mapWithKeys(fn (ActiveWwa $wwa): array => [
                $wwa->latestIngestedProduct->test_product_scenario_id => sprintf(
                    '%s — %s — #%s — issued %s',
                    $wwa->product?->name ?? $wwa->vtec_phenomenon.'.'.$wwa->vtec_significance,
                    $wwa->vtec_office,
                    $wwa->vtec_event_number,
                    $wwa->updated_at->diffForHumans(),
                ),
            ])
            ->all();
    }

    /**
     * Copies the continued event's product/WFO/event-number identity plus
     * its most recent geography/content fields into this new scenario's
     * form state, so the admin only has to change what's actually different
     * for the follow-up (e.g. an updated impact statement) rather than
     * re-entering everything.
     */
    protected static function inheritFromContinuedScenario(Set $set, Get $get): void
    {
        $continuedScenarioId = $get('continues_scenario_id');
        if ($continuedScenarioId === null) {
            return;
        }

        $continuedScenario = TestProductScenario::find($continuedScenarioId);
        $wwa = ActiveWwa::query()
            ->where('is_test', true)
            ->where('status', 'Active')
            ->whereHas('latestIngestedProduct', fn (Builder $q) => $q->where('test_product_scenario_id', $continuedScenarioId))
            ->first();

        if ($continuedScenario === null || $wwa === null) {
            return;
        }

        $set('product_id', $continuedScenario->product_id);
        $set('input.wfo', $wwa->vtec_office !== null ? substr($wwa->vtec_office, 1) : null);
        $set('input.vtec_event_number', $wwa->vtec_event_number);

        foreach (['ugc_codes', 'hazard', 'hazard_source', 'impact', 'locations_impacted', 'call_to_action', 'polygon_center_lat', 'polygon_center_lon', 'polygon_radius_miles', 'polygon_advanced', 'polygon_raw', 'damage_tags'] as $key) {
            if (array_key_exists($key, $continuedScenario->input)) {
                $set("input.{$key}", $continuedScenario->input[$key]);
            }
        }
    }

    protected static function selectedProductHasVtec(Get $get): bool
    {
        if ($get('mode') === 'continue') {
            return true;
        }

        $product = Product::find($get('product_id'));

        return $product?->has_vtec ?? false;
    }
}
