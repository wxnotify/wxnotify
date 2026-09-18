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
    /**
     * input.* keys copied verbatim from the continued scenario's own
     * `input` array (geography/content fields that don't need any
     * transformation, unlike wfo/vtec_event_number which are re-derived
     * from the open ActiveWwa row instead). Shared between
     * inheritFromContinuedScenario() (live form state) and
     * inheritedDataFor() (the dehydration-independent copy used by
     * CreateTestProductScenario::mutateFormDataBeforeCreate()) so the two
     * never drift apart.
     *
     * @var array<int, string>
     */
    protected const INHERITED_INPUT_KEYS = [
        'ugc_codes', 'hazard', 'hazard_source', 'impact', 'locations_impacted', 'call_to_action',
        'polygon_center_lat', 'polygon_center_lon', 'polygon_radius_miles', 'polygon_advanced', 'polygon_raw', 'damage_tags',
    ];

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
                    ->afterStateUpdated(fn (Set $set, Get $get) => self::clearInheritedFieldsIfModeIsNew($set, $get))
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
     * LIVE form state, so the admin only has to change what's actually
     * different for the follow-up (e.g. an updated impact statement)
     * rather than re-entering everything.
     *
     * This alone is NOT sufficient to persist the inheritance on save --
     * `product_id` and `input.wfo` are only ever set here while
     * `mode === 'continue'`, which is exactly when both fields are
     * `->visible(false)`. Filament's default `isDehydratedWhenHidden(false)`
     * strips a hidden field's key from dehydrated state entirely (the same
     * trap documented in this file's rule doc for EditAlertRule's
     * alert_template_id), so a bare `$record->create($data)` would silently
     * drop them. CreateTestProductScenario::mutateFormDataBeforeCreate()
     * re-derives the same data via inheritedDataFor() below, independent of
     * dehydration, as the actual point that writes to the database.
     */
    protected static function inheritFromContinuedScenario(Set $set, Get $get): void
    {
        $continuedScenarioId = $get('continues_scenario_id');
        if ($continuedScenarioId === null) {
            return;
        }

        $inherited = self::inheritedDataFor((int) $continuedScenarioId);
        if ($inherited === null) {
            return;
        }

        $set('product_id', $inherited['product_id']);

        foreach ($inherited['input'] as $key => $value) {
            $set("input.{$key}", $value);
        }
    }

    /**
     * Re-derives the same product/WFO/event-number/content inheritance as
     * inheritFromContinuedScenario() above, but as a plain array rather
     * than a `Set $set` side effect -- usable from a `mutateFormDataBe...`
     * page hook (which works with a `$data` array, not live Livewire
     * state) as well as from the live form. Returns null when the
     * continued scenario or its open ActiveWwa row can no longer be found
     * (defense-in-depth; shouldn't happen for a scenario id that came from
     * openSyntheticEventOptions()).
     *
     * @return array{product_id: ?int, input: array<string, mixed>}|null
     */
    public static function inheritedDataFor(int $continuedScenarioId): ?array
    {
        $continuedScenario = TestProductScenario::find($continuedScenarioId);
        $wwa = ActiveWwa::query()
            ->where('is_test', true)
            ->where('status', 'Active')
            ->whereHas('latestIngestedProduct', fn (Builder $q) => $q->where('test_product_scenario_id', $continuedScenarioId))
            ->first();

        if ($continuedScenario === null || $wwa === null) {
            return null;
        }

        $input = [
            'wfo' => $wwa->vtec_office !== null ? substr($wwa->vtec_office, 1) : null,
            'vtec_event_number' => $wwa->vtec_event_number,
        ];

        foreach (self::INHERITED_INPUT_KEYS as $key) {
            if (array_key_exists($key, $continuedScenario->input)) {
                $input[$key] = $continuedScenario->input[$key];
            }
        }

        return [
            'product_id' => $continuedScenario->product_id,
            'input' => $input,
        ];
    }

    /**
     * Mirrors AlertRuleForm::clearAlertTemplateIfCategoryUnsupported()'s
     * pattern: switching `mode` back to 'new' (whether or not an event was
     * ever picked to continue) drops whatever `continues_scenario_id` and
     * inherited product/wfo/event-number/content values are currently
     * sitting in live form state, so they can't linger and get silently
     * reused if the admin flips back to 'continue' and picks a different
     * event, or accidentally leaves stale data behind for the "New event"
     * path to build on. Only clears -- it never runs on the 'continue'
     * branch, where inheritFromContinuedScenario() is the one setting
     * these same keys.
     */
    protected static function clearInheritedFieldsIfModeIsNew(Set $set, Get $get): void
    {
        if ($get('mode') === 'continue') {
            return;
        }

        $set('continues_scenario_id', null);
        $set('product_id', null);
        $set('input.wfo', null);
        $set('input.vtec_event_number', null);

        foreach (self::INHERITED_INPUT_KEYS as $key) {
            $set("input.{$key}", null);
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
