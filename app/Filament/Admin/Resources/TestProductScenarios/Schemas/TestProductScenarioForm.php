<?php

namespace App\Filament\Admin\Resources\TestProductScenarios\Schemas;

use App\Actions\Testing\CheckPolygonWithinWfo;
use App\Actions\Testing\GenerateTestProductPolygon;
use App\Actions\Testing\RenderTestProductText;
use App\Enums\ProductFamily;
use App\Models\ActiveWwa;
use App\Models\Product;
use App\Models\TestProductScenario;
use Closure;
use Fahiem\FilamentPinpoint\Pinpoint;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
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
            self::geographyStep(),
            self::contentStep(),
            self::reviewStep(),
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
                TextInput::make('name')
                    ->label('Scenario name (optional)')
                    ->maxLength(255),
                Radio::make('mode')
                    ->label('')
                    ->options(['new' => 'New event', 'continue' => 'Continue existing event'])
                    ->default('new')
                    ->live()
                    ->afterStateUpdated(fn (Set $set, Get $get) => self::clearInheritedFieldsIfModeIsNew($set, $get))
                    ->visible(fn (): bool => self::openSyntheticEventOptions() !== []),
                Select::make('continues_scenario_id')
                    ->label('Continue which event?')
                    ->options(fn (?TestProductScenario $record): array => self::continueEventOptionsFor($record))
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
     * The lat/lon backing fields use Hidden (not TextInput->hidden()) --
     * Filament's CanBeHidden trait treats an unconditional ->hidden() as
     * permanently hidden regardless of any later ->visible() call
     * (isHidden() short-circuits true before ->visible() is ever
     * evaluated), which combined with the default isDehydratedWhenHidden
     * (false) would strip these keys from dehydrated state every time --
     * defeating the entire point of capturing a center point. Hidden
     * doesn't touch CanBeHidden's flags at all (it only changes the
     * rendered `<input type="hidden">`), so the ->visible() condition below
     * is the only thing controlling dehydration, exactly like input.wfo
     * elsewhere in this form.
     */
    protected static function geographyStep(): Step
    {
        return Step::make('Geography')
            ->schema([
                CheckboxList::make('input.ugc_codes')
                    ->label('Counties / zones (UGC)')
                    ->columns(2)
                    ->options(fn (Get $get): array => self::wfoScopedUgcOptions($get('input.wfo') ?? ''))
                    ->required()
                    ->minItems(1)
                    ->visible(fn (Get $get): bool => self::selectedProductHasUgc($get)),
                Toggle::make('input.polygon_advanced')
                    ->label('Advanced: paste raw coordinates')
                    ->live()
                    ->visible(fn (Get $get): bool => self::selectedProductHasPolygon($get)),
                Pinpoint::make('input.polygon_center')
                    ->label('Center point')
                    ->provider('leaflet')
                    ->latField('input.polygon_center_lat')
                    ->lngField('input.polygon_center_lon')
                    ->dehydrated(false)
                    ->visible(fn (Get $get): bool => self::selectedProductHasPolygon($get) && ! $get('input.polygon_advanced')),
                Hidden::make('input.polygon_center_lat')
                    ->live()
                    ->visible(fn (Get $get): bool => self::selectedProductHasPolygon($get) && ! $get('input.polygon_advanced')),
                Hidden::make('input.polygon_center_lon')
                    ->live()
                    ->visible(fn (Get $get): bool => self::selectedProductHasPolygon($get) && ! $get('input.polygon_advanced')),
                TextInput::make('input.polygon_radius_miles')
                    ->label('Radius (miles)')
                    ->numeric()
                    ->minValue(1)
                    ->default(10)
                    ->live()
                    ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        if (blank($get('input.polygon_center_lat')) || blank($get('input.polygon_center_lon'))) {
                            $fail('Place the center point on the map (or use raw coordinates) before continuing.');
                        }
                    })
                    ->visible(fn (Get $get): bool => self::selectedProductHasPolygon($get) && ! $get('input.polygon_advanced')),
                Textarea::make('input.polygon_raw')
                    ->label('Coordinates (one "lat,lon" pair per line)')
                    ->rows(4)
                    ->required()
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (app(GenerateTestProductPolygon::class)->fromRawText((string) $value) === null) {
                            $fail('Enter at least 3 valid "lat,lon" pairs, one per line.');
                        }
                    })
                    ->visible(fn (Get $get): bool => self::selectedProductHasPolygon($get) && $get('input.polygon_advanced')),
                // A real Field (not the deprecated Placeholder, which now
                // extends Infolists\TextEntry rather than Forms\Field and so
                // is invisible to getFlatFields()/assertFormFieldVisible())
                // is required here so the advisory warning's visibility is
                // actually testable.
                ViewField::make('wfo_wide_note')
                    ->label('')
                    ->view('filament.forms.components.wfo-wide-note')
                    ->dehydrated(false)
                    ->visible(fn (Get $get): bool => self::selectedProduct($get) !== null
                        && ! self::selectedProductHasUgc($get)
                        && ! self::selectedProductHasPolygon($get)),
                ViewField::make('wfo_sanity_warning')
                    ->label('')
                    ->view('filament.forms.components.wfo-sanity-warning')
                    ->viewData(fn (Get $get): array => ['message' => self::wfoSanityWarning($get)])
                    ->dehydrated(false)
                    ->visible(fn (Get $get): bool => self::selectedProductHasPolygon($get) && self::wfoSanityWarning($get) !== null),
            ]);
    }

    protected static function contentStep(): Step
    {
        return Step::make('Content')
            ->schema([
                // Warning-shaped
                TextInput::make('input.hazard')
                    ->label('Hazard')
                    ->required()
                    ->visible(fn (Get $get): bool => self::selectedProductHasVtec($get)),
                TextInput::make('input.hazard_source')
                    ->label('Source')
                    ->required()
                    ->visible(fn (Get $get): bool => self::selectedProductHasVtec($get)),
                TextInput::make('input.impact')
                    ->label('Impact')
                    ->required()
                    ->visible(fn (Get $get): bool => self::selectedProductHasVtec($get)),
                TextInput::make('input.locations_impacted')
                    ->label('Locations impacted (optional)')
                    ->visible(fn (Get $get): bool => self::selectedProductHasVtec($get)),
                Textarea::make('input.call_to_action')
                    ->label('Call to action')
                    ->required()
                    ->visible(fn (Get $get): bool => self::selectedProductHasVtec($get)),
                CheckboxList::make('input.damage_tags')
                    ->label('Damage tags')
                    ->options(fn (Get $get): array => self::selectedProductDamageTagOptions($get))
                    ->visible(fn (Get $get): bool => self::selectedProductDamageTagOptions($get) !== []),
                TextInput::make('input.motion_direction_degrees')
                    ->label('Motion direction (degrees, optional)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(359)
                    ->visible(fn (Get $get): bool => self::selectedProductHasVtec($get)),
                TextInput::make('input.motion_speed_kt')
                    ->label('Motion speed (knots, optional)')
                    ->numeric()
                    ->minValue(0)
                    ->visible(fn (Get $get): bool => self::selectedProductHasVtec($get)),

                // Discussion-shaped (SPC MD / WPC MPD)
                Textarea::make('input.discussion_text')
                    ->label('Discussion text')
                    ->required()
                    ->rows(4)
                    ->visible(fn (Get $get): bool => self::selectedProductIsDiscussion($get)),

                TextInput::make('input.areas_affected')
                    ->label('Areas affected')
                    ->visible(fn (Get $get): bool => self::selectedProductIsDiscussion($get)),
                TextInput::make('input.concerning')
                    ->label('Concerning (e.g. "Severe potential...Watch unlikely")')
                    ->visible(fn (Get $get): bool => self::selectedProductIsDiscussion($get)),

                // Plain-text-shaped (AFD, SPS)
                Textarea::make('input.body_text')
                    ->label('Body text')
                    ->required()
                    ->rows(6)
                    ->visible(fn (Get $get): bool => self::selectedProductIsPlainText($get)),
            ]);
    }

    protected static function reviewStep(): Step
    {
        return Step::make('Review & Generate')
            ->schema([
                ViewField::make('rendered_preview')
                    ->label('Preview')
                    ->view('filament.forms.components.rendered-preview')
                    ->viewData(fn (Get $get): array => [
                        'text' => self::renderPreview($get),
                    ])
                    ->dehydrated(false),
            ]);
    }

    protected static function renderPreview(Get $get): string
    {
        $product = self::selectedProduct($get);
        if ($product === null) {
            return 'Select a product to see a preview.';
        }

        return self::renderPreviewFor($product, self::currentInput($get));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function renderPreviewFor(Product $product, array $input): string
    {
        try {
            return app(RenderTestProductText::class)($product, $input);
        } catch (\Throwable $e) {
            report($e);

            return 'Fill in the required fields above to see a preview.';
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function currentInput(Get $get): array
    {
        return $get('input') ?? [];
    }

    /**
     * Memoizes the repeated Product::find($get('product_id')) lookups this
     * form's visibility helpers all make -- selectedProductHasVtec()/
     * selectedProductHasUgc()/selectedProductHasPolygon() (Steps 1/2) plus
     * Step 3's own helpers below. A single field re-evaluation previously
     * fired one query per helper call across all three steps (measured at
     * 20, then still 15 after only Step 3's own helpers were routed through
     * this cache -- selectedProductHasVtec() alone accounts for most of Step
     * 3's field visibility and was still calling Product::find() directly).
     * Safe across the request/response lifecycle used here (plain PHP-FPM,
     * no Octane): these static properties are reset for every fresh
     * request, and within a request the cache is keyed by product id, so
     * switching product_id (e.g. via a live Select, or a test driving
     * multiple products through the same Livewire instance) always
     * re-fetches rather than returning stale data for a different id.
     */
    protected static ?Product $cachedProduct = null;

    protected static ?int $cachedProductId = null;

    protected static function selectedProduct(Get $get): ?Product
    {
        $id = $get('product_id');
        if ($id === null) {
            return null;
        }

        if (self::$cachedProductId !== (int) $id) {
            self::$cachedProduct = Product::find($id);
            self::$cachedProductId = (int) $id;
        }

        return self::$cachedProduct;
    }

    /**
     * @return array<string, string>
     */
    protected static function selectedProductDamageTagOptions(Get $get): array
    {
        $options = self::selectedProduct($get)?->damage_tag_options ?? [];

        return collect($options)->mapWithKeys(fn (string $tag): array => [$tag => $tag])->all();
    }

    protected static function selectedProductIsDiscussion(Get $get): bool
    {
        return self::selectedProduct($get)?->family === ProductFamily::SpcWpc;
    }

    protected static function selectedProductIsPlainText(Get $get): bool
    {
        $product = self::selectedProduct($get);

        return $product !== null && ! $product->has_vtec && $product->family !== ProductFamily::SpcWpc;
    }

    /**
     * counties.cwa/public_zones.cwa can list more than one office for a
     * shared boundary area, so `like` (not `=`) is deliberate here --
     * mirrors AlertRuleForm::countyZoneOptions()'s county/zone code
     * derivation, just scoped by WFO instead of state.
     *
     * @return array<string, string>
     */
    public static function wfoScopedUgcOptions(string $wfo): array
    {
        if ($wfo === '') {
            return [];
        }

        $countyOptions = DB::table('counties')->where('cwa', 'like', "%{$wfo}%")->get(['fips', 'state', 'countyname'])
            ->mapWithKeys(function ($c): array {
                $code = strtoupper($c->state).'C'.substr($c->fips, -3);

                return [$code => "{$code} — {$c->countyname} County, {$c->state}"];
            });

        $zoneOptions = DB::table('public_zones')->where('cwa', 'like', "%{$wfo}%")->get(['state_zone', 'state', 'zone', 'name'])
            ->mapWithKeys(function ($z): array {
                $code = strtoupper($z->state).'Z'.$z->zone;

                return [$code => "{$code} — {$z->name}, {$z->state}"];
            });

        return $countyOptions->union($zoneOptions)->all();
    }

    protected static function selectedProductHasUgc(Get $get): bool
    {
        return self::selectedProduct($get)?->has_ugc ?? false;
    }

    protected static function selectedProductHasPolygon(Get $get): bool
    {
        return self::selectedProduct($get)?->has_polygon ?? false;
    }

    /**
     * Non-blocking: a real warning legitimately spans multiple CWAs, so this
     * is a signal surfaced to catch an obviously wrong point, never a hard
     * validation rule. Returns null (no warning shown) whenever the WFO or
     * polygon isn't resolvable yet, so an incomplete Step 2 doesn't show a
     * false positive.
     */
    protected static function wfoSanityWarning(Get $get): ?string
    {
        $points = self::resolveGeographyPolygon($get);

        if ($points === null && $get('input.polygon_advanced') && filled($get('input.polygon_raw'))) {
            return 'Could not read at least 3 valid "lat,lon" pairs from the coordinates, so the polygon cannot be checked against the WFO.';
        }

        $wfo = $get('input.wfo');
        if (blank($wfo) || $points === null) {
            return null;
        }

        $within = app(CheckPolygonWithinWfo::class)($points, $wfo);

        return $within ? null : "This point falls outside {$wfo}'s forecast area — real warnings occasionally span multiple offices, but double check this is what you intended.";
    }

    /**
     * @return ?array<int, array{lat: float, lon: float}>
     */
    protected static function resolveGeographyPolygon(Get $get): ?array
    {
        if ($get('input.polygon_advanced')) {
            return app(GenerateTestProductPolygon::class)->fromRawText($get('input.polygon_raw') ?? '');
        }

        $lat = $get('input.polygon_center_lat');
        $lon = $get('input.polygon_center_lon');
        $radius = $get('input.polygon_radius_miles');

        if (blank($lat) || blank($lon) || blank($radius)) {
            return null;
        }

        return app(GenerateTestProductPolygon::class)->fromPointAndRadius((float) $lat, (float) $lon, (float) $radius);
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
     * SVS comes to exist. VTEC-flagged warning/watch/advisory products are
     * further limited to codes shaped like a real VTEC
     * phenomenon.significance pair (e.g. TO.W): the catalog also holds
     * legacy dotless AWIPS abbreviations (FAW, SEW...) flagged has_vtec, for
     * which no parseable VTEC line can be rendered (and which the real
     * pipeline can't resolve from a VTEC line either). Every status, including draft, is included for
     * the eligible families -- this is the concrete use case draft status
     * was built for.
     */
    public static function eligibleProductsQuery(): Builder
    {
        return Product::query()
            ->where(function (Builder $q): void {
                $q->where(fn (Builder $q3) => $q3->whereIn('family', ['warning', 'watch', 'advisory'])
                    ->where(fn (Builder $q4) => $q4->where('has_vtec', false)->orWhereRaw("code ~ '^[A-Z]{2}\\.[A-Z]$'")))
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
            ->where(fn (Builder $q) => $q->whereNull('expires')->orWhere('expires', '>', now()))
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
     * The open-event options, plus -- when editing an existing continuation --
     * its own already-selected continued scenario. After a CAN/EXP the event
     * is no longer "open", so without this the Select's saved value would
     * fall outside its options and fail Filament's built-in "in options"
     * validation on every Edit save. Other closed events stay unlisted.
     *
     * @return array<int, string>
     */
    public static function continueEventOptionsFor(?TestProductScenario $record): array
    {
        $options = self::openSyntheticEventOptions();

        $continuedId = $record?->continues_scenario_id;
        if ($continuedId === null || array_key_exists($continuedId, $options)) {
            return $options;
        }

        $continued = TestProductScenario::with('product')->find($continuedId);
        if ($continued === null) {
            return $options;
        }

        $options[$continuedId] = sprintf(
            '%s — %s — #%s (no longer open)',
            $continued->product?->name ?? 'Product',
            $record->input['wfo'] ?? '?',
            $record->input['vtec_event_number'] ?? '?',
        );

        return $options;
    }

    /**
     * Copies the continued event's product/WFO/event-number identity plus
     * its most recent geography/content fields into this new scenario's
     * LIVE form state, so the admin only has to change what's actually
     * different for the follow-up (e.g. an updated impact statement)
     * rather than re-entering everything. This is the correct place for
     * the FULL inheritance -- pre-filling a sensible default the admin can
     * then freely edit before submitting.
     *
     * Note this alone is NOT sufficient to persist product_id/input.wfo/
     * input.vtec_event_number on save -- see
     * dehydrationSafeInheritedFieldsFor()'s docblock below for why, and why
     * the rest of the inherited input.* keys deliberately do NOT get the
     * same save-time treatment.
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
     * The full product/WFO/event-number/geography/content inheritance, as
     * a plain array rather than a `Set $set` side effect -- used by
     * inheritFromContinuedScenario() above to pre-fill LIVE form state only.
     * Deliberately NOT used by CreateTestProductScenario::
     * mutateFormDataBeforeCreate() -- see
     * dehydrationSafeInheritedFieldsFor() below for the narrower subset
     * that save-time hook actually needs. Returns null when the continued
     * scenario or its open ActiveWwa row can no longer be found
     * (defense-in-depth; shouldn't happen for a scenario id that came from
     * openSyntheticEventOptions()).
     *
     * @return array{product_id: ?int, input: array<string, mixed>}|null
     */
    public static function inheritedDataFor(int $continuedScenarioId): ?array
    {
        $found = self::findContinuedScenarioAndWwa($continuedScenarioId);
        if ($found === null) {
            return null;
        }
        [$continuedScenario, $wwa] = $found;

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
     * ONLY the fields that are structurally guaranteed to be
     * ->visible(false) -- and therefore dropped by Filament's default
     * `isDehydratedWhenHidden(false)` -- while `mode === 'continue'`:
     * `product_id` and `input.wfo`/`input.vtec_event_number` all live on
     * THIS step's schema and are hidden by exactly that condition (see
     * productAndTimingStep() above). A hidden field's key is stripped from
     * dehydrated state entirely (the same trap documented in this app's
     * rule doc for EditAlertRule's alert_template_id), so
     * CreateTestProductScenario::mutateFormDataBeforeCreate() force-fills
     * these three back in, independent of dehydration, as the actual point
     * that writes to the database.
     *
     * Deliberately does NOT include the rest of INHERITED_INPUT_KEYS
     * (ugc_codes, hazard, impact, ...). Those geography/content fields
     * belong to Steps 2/3 (Geography, Content) which -- once built -- stay
     * visible and editable regardless of mode, so they dehydrate normally
     * and their submitted value is whatever the admin actually typed. If
     * this method force-filled them too, an admin who deliberately edited
     * an inherited value during a "continue" flow would have that edit
     * silently overwritten by the original scenario's value at save time.
     * inheritFromContinuedScenario() above is the correct (and only) place
     * those fields get inherited -- as a LIVE pre-fill the admin can then
     * change, not a save-time override.
     *
     * @return array{product_id: ?int, input: array{wfo: ?string, vtec_event_number: ?string}}|null
     */
    public static function dehydrationSafeInheritedFieldsFor(int $continuedScenarioId): ?array
    {
        $found = self::findContinuedScenarioAndWwa($continuedScenarioId);
        if ($found === null) {
            return null;
        }
        [$continuedScenario, $wwa] = $found;

        return [
            'product_id' => $continuedScenario->product_id,
            'input' => [
                'wfo' => $wwa->vtec_office !== null ? substr($wwa->vtec_office, 1) : null,
                'vtec_event_number' => $wwa->vtec_event_number,
            ],
        ];
    }

    /**
     * Shared lookup behind inheritedDataFor() and
     * dehydrationSafeInheritedFieldsFor() -- the continued scenario and its
     * currently-open ActiveWwa row (via the same VTEC-owning-scenario
     * correlation openSyntheticEventOptions() uses).
     *
     * @return array{0: TestProductScenario, 1: ActiveWwa}|null
     */
    protected static function findContinuedScenarioAndWwa(int $continuedScenarioId): ?array
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

        return [$continuedScenario, $wwa];
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

        return self::selectedProduct($get)?->has_vtec ?? false;
    }
}
