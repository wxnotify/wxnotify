<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Schemas;

use App\Actions\Alerts\BuildAlertRuleOptions;
use App\Actions\Weather\SearchUgcCodes;
use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Enums\ProductCategory;
use App\Filament\Dashboard\Resources\Locations\Schemas\LocationForm;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Support\ProductMatching;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use WeakMap;

/**
 * The alert wizard's per-product table for one product category: a switch, the product's name
 * and description, and its matching mode with that mode's inputs, one row per product.
 *
 * State, `product_settings.{category}`: a list of rows `{product_id, enabled, matching_mode,
 * location_ids, wfo, county_zone_codes}`, one per product of the category whether it is on or
 * not, seeded by formState() (never by field defaults: the step is built dynamically).
 *
 * A custom field with a Blade view of native inputs, not Filament Selects or a Repeater: the
 * table-mode Repeater was about 46,000 DOM nodes and a 9.6 MB response for the 143 Weather
 * Alerts rows (see `.ai/rules/filament.md`). The view binds with deferred `wire:model` /
 * `$wire.set(..., false)`, so a switch, a mode change or a location tick makes no request;
 * the mode's inputs, its helper text and the dimming of a switched-off row are Alpine. Only the
 * county/zone picker and "add a location" are server actions (modals), since they need the
 * existing Filament fields.
 */
class ProductMatchingTable extends Field
{
    public const LOCATION_MODES = [MatchingMode::Location, MatchingMode::Polygon, MatchingMode::RiskArea];

    protected string $view = 'filament.dashboard.alert-rules.product-matching-table';

    protected AlertCategory $alertCategory;

    protected ProductCategory $productCategory;

    /** @var Collection<int, Product> keyed by id, in display order */
    protected Collection $products;

    /**
     * Set on the first table only: the state keys of every table on the form, which together
     * must hold at least one switched-on product.
     *
     * @var list<string>|null
     */
    protected ?array $requiresAProductAcross = null;

    protected bool $rendersOfficeList = false;

    /**
     * Per-request lookups (the subscriber's locations, the office list), keyed by the Livewire
     * component so they are read once per request however often the step's schema is rebuilt.
     *
     * @var WeakMap<object, array<string, mixed>>|null
     */
    private static ?WeakMap $lookups = null;

    /**
     * One collapsible section per product category of the alert category: the table, and for
     * SPC & WPC the category's risk-level checklist. Tornado and Severe Thunderstorm starts
     * expanded; any other category is collapsed unless it holds a switched-on product.
     *
     * @param  list<int>  $watchedProductIds  the edited rule's products (it may watch a retired one); never form state
     * @return array<Section>
     */
    public static function sections(AlertCategory $alertCategory, array $watchedProductIds = []): array
    {
        $productsByCategory = self::catalog($alertCategory, $watchedProductIds)
            ->groupBy(fn (Product $product): ?string => $product->category?->value);
        $tableKeys = collect(ProductCategory::cases())
            ->filter(fn (ProductCategory $category): bool => $productsByCategory->has($category->value))
            ->map(fn (ProductCategory $category): string => $category->value)
            ->values()
            ->all();

        return collect(ProductCategory::cases())
            ->filter(fn (ProductCategory $category): bool => $productsByCategory->has($category->value))
            ->values()
            ->map(function (ProductCategory $category, int $index) use ($alertCategory, $productsByCategory, $tableKeys): Section {
                $table = self::make("product_settings.{$category->value}")
                    ->hiddenLabel()
                    ->forCategory($alertCategory, $category, $productsByCategory->get($category->value)->keyBy('id'))
                    // The first table carries the "at least one product" check (it reads every
                    // category, so once is enough) and the one office list every table shares.
                    ->requiresAProductAcross($index === 0 ? $tableKeys : null)
                    ->rendersOfficeList($index === 0);

                return Section::make($category->label())
                    ->icon($category->icon())
                    ->collapsible()
                    ->collapsed(fn (Get $get): bool => $category !== ProductCategory::TornadoAndSevereThunderstorm
                        && self::enabledProductIds($get("product_settings.{$category->value}")) === [])
                    ->schema([
                        $table,
                        ...($alertCategory === AlertCategory::SpcWpc ? AlertRuleForm::riskLevelFields($category) : []),
                    ]);
            })
            ->all();
    }

    public static function supports(?AlertCategory $category): bool
    {
        return in_array($category, [AlertCategory::WeatherAlerts, AlertCategory::SpcWpc], true);
    }

    /**
     * The table's products: the alert category's active products, plus any of its products the
     * edited rule watches (a rule keeps a product that has since been retired), in display order.
     *
     * @param  list<int>  $includeProductIds
     * @return Collection<int, Product>
     */
    public static function catalog(AlertCategory $alertCategory, array $includeProductIds = []): Collection
    {
        return Product::query()
            ->when(
                $alertCategory === AlertCategory::SpcWpc,
                fn (Builder $query): Builder => $query->where('family', 'spc_wpc'),
                fn (Builder $query): Builder => $query->whereIn('family', BuildAlertRuleOptions::WEATHER_FAMILIES),
            )
            ->where(fn (Builder $query): Builder => $query->where('status', 'active')->orWhereIn('id', $includeProductIds))
            ->whereNotNull('category')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'description', 'category', 'has_polygon', 'risk_level_options']);
    }

    /**
     * The `product_settings` state for an alert category: every product's row, by product
     * category. A row already in the form is kept (the category was switched away and back); a
     * product the rule watches is switched on with its stored settings (an old-style rule's
     * products read the rule's own); anything else starts switched off at its default.
     *
     * @return array<string, list<array{product_id: int, enabled: bool, matching_mode: string, location_ids: list<int>, wfo: ?string, county_zone_codes: list<string>}>>
     */
    public static function formState(User $user, AlertCategory $alertCategory, ?AlertRule $rule = null, mixed $currentState = null): array
    {
        $watched = $rule !== null && $rule->category === $alertCategory ? $rule->products->pluck('id')->all() : [];
        $current = collect(is_array($currentState) ? $currentState : [])
            ->flatten(1)
            ->filter(fn (mixed $row): bool => is_array($row) && is_numeric($row['product_id'] ?? null))
            ->keyBy(fn (array $row): int => (int) $row['product_id']);
        $firstLocationId = $user->locations()->orderBy('id')->value('id');

        return self::catalog($alertCategory, $watched)
            ->groupBy(fn (Product $product): string => $product->category->value)
            ->map(fn (Collection $products): array => $products->map(fn (Product $product): array => $current->get($product->id)
                ?? (in_array($product->id, $watched, true)
                    ? self::storedRow($user, $alertCategory, $product, $rule, $firstLocationId)
                    : ProductMatching::defaultRow($user, $alertCategory, $product, $firstLocationId)))->values()->all())
            ->all();
    }

    /**
     * @return array{product_id: int, enabled: bool, matching_mode: string, location_ids: list<int>, wfo: ?string, county_zone_codes: list<string>}
     */
    private static function storedRow(User $user, AlertCategory $alertCategory, Product $product, AlertRule $rule, ?int $firstLocationId): array
    {
        $settings = $rule->settingsFor($product->id);
        $default = ProductMatching::defaultRow($user, $alertCategory, $product, $firstLocationId);

        if ($settings?->mode === null) {
            return [...$default, 'enabled' => true];
        }

        return [
            'product_id' => $product->id,
            'enabled' => true,
            'matching_mode' => $settings->mode->value,
            'location_ids' => $settings->locations->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all(),
            'wfo' => $settings->wfo,
            'county_zone_codes' => $settings->countyZoneCodes,
        ];
    }

    /**
     * The form data SaveAlertRule reads: for a product-based rule, `product_settings` becomes the
     * flat list of switched-on rows across every category (ids as ints, the office code trimmed
     * and upper-cased). The pages call it once, on the validated, dehydrated form data (which
     * holds only the form's own tables), and reuse the result after the record is written: never
     * on the raw `$this->data`, where a tampered request can add keys no table validated. Only
     * keys named after a product category are read.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toSaveInput(array $data): array
    {
        if (! self::supports(AlertCategory::tryFrom((string) ($data['category'] ?? '')))) {
            unset($data['product_settings']);

            return $data;
        }

        $tables = collect(is_array($data['product_settings'] ?? null) ? $data['product_settings'] : [])
            ->filter(fn (mixed $rows, int|string $key): bool => ProductCategory::tryFrom((string) $key) !== null);

        $data['product_settings'] = $tables->flatMap(fn (mixed $rows): array => self::enabledRows($rows))
            ->map(fn (array $row): array => [
                'product_id' => (int) $row['product_id'],
                'matching_mode' => (string) ($row['matching_mode'] ?? ''),
                'location_ids' => self::ids($row['location_ids'] ?? []),
                'wfo' => self::officeCode($row['wfo'] ?? null),
                'county_zone_codes' => self::codes($row['county_zone_codes'] ?? []),
            ])
            ->values()
            ->all();

        return $data;
    }

    /**
     * The switched-on rows of one table's state (`product_settings.{category}`).
     *
     * @return list<array<string, mixed>>
     */
    public static function enabledRows(mixed $state): array
    {
        if (! is_array($state) || ! array_is_list($state)) {
            return [];
        }

        return collect($state)
            ->filter(fn (mixed $row): bool => is_array($row)
                && is_numeric($row['product_id'] ?? null)
                && filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOL))
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    public static function enabledProductIds(mixed $state): array
    {
        return collect(self::enabledRows($state))->map(fn (array $row): int => (int) $row['product_id'])->unique()->values()->all();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->showAllValidationMessages();

        // Implicit, so it also runs when a tampered request removed the table's key entirely (a
        // plain closure rule is skipped for an absent attribute, and the form would save no product).
        $this->rules([
            fn (ProductMatchingTable $component, Get $get): ValidationRule => new class($component, $get) implements ValidationRule
            {
                public bool $implicit = true;

                public function __construct(private ProductMatchingTable $component, private Get $get) {}

                public function validate(string $attribute, mixed $value, Closure $fail): void
                {
                    $this->component->validateState($value, $this->get, $fail);
                }
            },
        ]);

        $this->registerActions([
            fn (ProductMatchingTable $component): Action => $component->chooseZonesAction(),
            fn (ProductMatchingTable $component): Action => $component->addLocationAction(),
        ]);
    }

    /**
     * @param  Collection<int, Product>  $products  keyed by id
     */
    public function forCategory(AlertCategory $alertCategory, ProductCategory $productCategory, Collection $products): static
    {
        $this->alertCategory = $alertCategory;
        $this->productCategory = $productCategory;
        $this->products = $products;

        return $this;
    }

    /**
     * @param  list<string>|null  $tableKeys
     */
    public function requiresAProductAcross(?array $tableKeys): static
    {
        $this->requiresAProductAcross = $tableKeys;

        return $this;
    }

    public function rendersOfficeList(bool $condition = true): static
    {
        $this->rendersOfficeList = $condition;

        return $this;
    }

    public function shouldRenderOfficeList(): bool
    {
        return $this->rendersOfficeList;
    }

    public function getAlertCategory(): AlertCategory
    {
        return $this->alertCategory;
    }

    public function getProductCategory(): ProductCategory
    {
        return $this->productCategory;
    }

    /**
     * @return Collection<int, Product> keyed by id
     */
    public function getProducts(): Collection
    {
        return $this->products;
    }

    /**
     * The rows to draw: each well-formed row whose product belongs to this table, by its index
     * in the state (the index is the row's `wire:model` path).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRows(): array
    {
        $state = $this->getState();

        if (! is_array($state)) {
            return [];
        }

        return collect($state)
            ->filter(fn (mixed $row): bool => is_array($row) && $this->products->has((int) ($row['product_id'] ?? 0)))
            ->all();
    }

    /**
     * The modes a row's select offers: the product's offered modes, plus the row's own stored
     * mode when the table no longer offers it (shown as stored, never silently changed).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    public function modeOptionsFor(array $row): array
    {
        $product = $this->products->get((int) ($row['product_id'] ?? 0));

        if ($product === null) {
            return [];
        }

        $modes = ProductMatching::offeredModes($this->alertCategory, $product);
        $stored = MatchingMode::tryFrom((string) ($row['matching_mode'] ?? ''));

        if ($stored !== null && ! in_array($stored, $modes, true)) {
            $modes[] = $stored;
        }

        return collect(MatchingMode::cases())
            ->filter(fn (MatchingMode $mode): bool => in_array($mode, $modes, true))
            ->mapWithKeys(fn (MatchingMode $mode): array => [$mode->value => $mode->label()])
            ->all();
    }

    /**
     * Whether switching this product's row on or off re-renders the form at once: only an SPC &
     * WPC product with risk levels, whose switch decides whether the category's risk-level
     * checklist (server-rendered, see AlertRuleForm::riskLevelFields()) is shown. Every other
     * switch is deferred.
     */
    public function switchRefreshesForm(Product $product): bool
    {
        return $this->alertCategory === AlertCategory::SpcWpc && filled($product->risk_level_options);
    }

    /**
     * @return array<string, string> each mode's helper text, keyed by mode
     */
    public function getHelperTexts(): array
    {
        return collect(MatchingMode::cases())->mapWithKeys(fn (MatchingMode $mode): array => [$mode->value => $mode->helperText()])->all();
    }

    /**
     * @return list<string>
     */
    public function getLocationModeValues(): array
    {
        return array_map(fn (MatchingMode $mode): string => $mode->value, self::LOCATION_MODES);
    }

    /**
     * The subscriber's saved locations, by label.
     *
     * @return list<array{id: int, label: string}>
     */
    public function getLocationOptions(): array
    {
        return $this->lookup('locations', fn (): array => auth()->user()->locations()->orderBy('label')->orderBy('id')->get(['id', 'label'])
            ->map(fn (Location $location): array => ['id' => (int) $location->id, 'label' => (string) $location->label])
            ->all());
    }

    public function canCreateLocation(): bool
    {
        return $this->lookup('canCreateLocation', fn (): bool => auth()->user()->canCreateLocation());
    }

    /**
     * Shown under a row's locations when the subscriber has none, as the old picker did.
     */
    public function getNoLocationsMessage(): ?string
    {
        if ($this->getLocationOptions() !== []) {
            return null;
        }

        return $this->canCreateLocation()
            ? 'No saved locations yet — use the + button to add one.'
            : 'You\'ve reached your location limit — remove one or upgrade to add another.';
    }

    /**
     * Every NWS office, "CODE — City, ST", keyed by its code.
     *
     * @return array<string, string>
     */
    public function getOfficeOptions(): array
    {
        return $this->lookup('offices', fn (): array => AlertRuleForm::wfoOptions());
    }

    public static function officeListId(): string
    {
        return 'alert-rule-nws-offices';
    }

    /**
     * Replace one product's row through a callback (the modal actions write back this way).
     *
     * @param  Closure(array<string, mixed>): array<string, mixed>  $change
     */
    public function updateRow(int $productId, Closure $change): void
    {
        $rows = $this->getState();

        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $index => $row) {
            if (is_array($row) && (int) ($row['product_id'] ?? 0) === $productId) {
                $rows[$index] = $change($row);
            }
        }

        $this->state($rows);
    }

    /**
     * The table's validation: on the first table, at least one switched-on product across every
     * table of the form; on each table, what problems() finds.
     */
    public function validateState(mixed $state, Get $get, Closure $fail): void
    {
        if ($this->requiresAProductAcross !== null
            && collect($this->requiresAProductAcross)->every(fn (string $key): bool => self::enabledProductIds($get("product_settings.{$key}")) === [])) {
            $fail('Select at least one product.');
        }

        foreach ($this->problems($state) as $problem) {
            $fail($problem);
        }
    }

    /**
     * What is wrong with this table's switched-on rows, one message per problem.
     *
     * @return list<string>
     */
    public function problems(mixed $state): array
    {
        $duplicates = collect(is_array($state) ? $state : [])
            ->filter(fn (mixed $row): bool => is_array($row) && is_numeric($row['product_id'] ?? null))
            ->countBy(fn (array $row): int => (int) $row['product_id'])
            ->filter(fn (int $count): bool => $count > 1)
            ->keys()
            ->map(fn (int $productId): string => ($this->products->get($productId)?->name ?? 'A product').' is listed more than once.')
            ->all();

        if ($duplicates !== []) {
            return array_values($duplicates);
        }

        $rows = self::enabledRows($state);

        if ($rows === []) {
            return [];
        }

        $accepted = array_map(fn (MatchingMode $mode): string => $mode->value, ProductMatching::acceptedModes($this->alertCategory));
        $ownLocationIds = auth()->user()->locations()
            ->whereIn('id', collect($rows)->flatMap(fn (array $row): array => self::ids($row['location_ids'] ?? []))->unique()->all())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $problems = [];

        foreach ($rows as $row) {
            $product = $this->products->get((int) $row['product_id']);

            if ($product === null) {
                $problems[] = 'Choose products from this list only.';

                continue;
            }

            $mode = MatchingMode::tryFrom((string) ($row['matching_mode'] ?? ''));

            if ($mode === null || ! in_array($mode->value, $accepted, true)) {
                $problems[] = "Choose a matching mode for {$product->name}.";

                continue;
            }

            $problems = [...$problems, ...match (true) {
                in_array($mode, self::LOCATION_MODES, true) => $this->locationProblems($product, $row, $ownLocationIds),
                $mode === MatchingMode::Wfo => $this->officeProblems($product, $row),
                $mode === MatchingMode::CountyZone => $this->countyZoneProblems($product, $row),
                default => [],
            }];
        }

        return array_values(array_unique($problems));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<int>  $ownLocationIds
     * @return list<string>
     */
    private function locationProblems(Product $product, array $row, array $ownLocationIds): array
    {
        $ids = self::ids($row['location_ids'] ?? []);

        return match (true) {
            $ids === [] => ["Choose at least one location for {$product->name}."],
            array_diff($ids, $ownLocationIds) !== [] => ["Choose only your own saved locations for {$product->name}."],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function officeProblems(Product $product, array $row): array
    {
        $code = self::officeCode($row['wfo'] ?? null);

        return $code !== null && DB::table('cwa_boundaries')->where('cwa', $code)->exists()
            ? []
            : ["Choose a known NWS office for {$product->name}."];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function countyZoneProblems(Product $product, array $row): array
    {
        $codes = self::codes($row['county_zone_codes'] ?? []);

        if ($codes === []) {
            return ["Choose at least one county or zone for {$product->name}."];
        }

        $search = app(SearchUgcCodes::class);

        return collect($codes)->every(fn (string $code): bool => $search->exists($code))
            ? []
            : ["Choose known counties or zones for {$product->name}."];
    }

    protected function chooseZonesAction(): Action
    {
        return Action::make('chooseZones')
            ->label('Choose zones')
            ->modalHeading(fn (array $arguments): string => 'Counties and zones for '.($this->products->get((int) ($arguments['product'] ?? 0))?->name ?? 'this product'))
            ->modalSubmitActionLabel('Use these zones')
            ->fillForm(function (array $arguments): array {
                $codes = self::codes($this->rowFor((int) ($arguments['product'] ?? 0))['county_zone_codes'] ?? []);

                return [
                    'county_zone_state' => $codes === [] ? null : substr($codes[0], 0, 2),
                    'county_zone_codes' => $codes,
                ];
            })
            ->schema([
                AlertRuleForm::countyZoneStateField(),
                AlertRuleForm::countyZoneField(),
            ])
            ->action(function (array $data, array $arguments): void {
                $codes = self::codes($data['county_zone_codes'] ?? []);

                $this->updateRow((int) ($arguments['product'] ?? 0), fn (array $row): array => [...$row, 'county_zone_codes' => $codes]);
            });
    }

    protected function addLocationAction(): Action
    {
        return Action::make('addLocation')
            ->label('Add a location')
            ->icon(Heroicon::Plus)
            ->modalHeading('Add a location')
            ->visible(fn (): bool => $this->canCreateLocation())
            // A new Location, not the alert rule being edited: LocationForm's closures type-hint
            // `?Location $record` (Select's own create-option modal sets its model the same way).
            ->schema(fn (Schema $schema): Schema => $schema->model(Location::class)->components(LocationForm::fields()))
            ->action(function (array $data, array $arguments): void {
                $location = auth()->user()->locations()->create($data);
                self::forgetLookups($this->getLivewire());

                $this->updateRow((int) ($arguments['product'] ?? 0), fn (array $row): array => [
                    ...$row,
                    'location_ids' => array_values(array_unique([...self::ids($row['location_ids'] ?? []), (int) $location->getKey()])),
                ]);
            });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rowFor(int $productId): ?array
    {
        return collect($this->getRows())->first(fn (array $row): bool => (int) $row['product_id'] === $productId);
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $ids): array
    {
        return collect(is_array($ids) ? $ids : [])
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private static function codes(mixed $codes): array
    {
        if (is_string($codes)) {
            $codes = explode(',', $codes);
        }

        return collect(is_array($codes) ? $codes : [])
            ->filter(fn (mixed $code): bool => is_string($code) && trim($code) !== '')
            ->map(fn (string $code): string => strtoupper(trim($code)))
            ->unique()
            ->values()
            ->all();
    }

    private static function officeCode(mixed $code): ?string
    {
        return is_string($code) && trim($code) !== '' ? strtoupper(trim($code)) : null;
    }

    private function lookup(string $key, Closure $resolve): mixed
    {
        $owner = $this->getLivewire();
        self::$lookups ??= new WeakMap;
        $cached = self::$lookups[$owner] ?? [];

        if (! array_key_exists($key, $cached)) {
            $cached[$key] = $resolve();
            self::$lookups[$owner] = $cached;
        }

        return $cached[$key];
    }

    private static function forgetLookups(object $owner): void
    {
        if (self::$lookups !== null) {
            unset(self::$lookups[$owner]);
        }
    }
}
