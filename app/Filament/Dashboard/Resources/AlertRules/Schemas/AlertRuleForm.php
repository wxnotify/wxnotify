<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Schemas;

use App\Actions\Channels\DispatchChannelConfirmationCode;
use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Enums\MessageBodyStyle;
use App\Enums\ProductCategory;
use App\Enums\ThresholdDirection;
use App\Enums\ThresholdTimeframe;
use App\Enums\ThresholdVariable;
use App\Enums\Tier;
use App\Filament\Dashboard\Resources\AlertTemplates\Schemas\AlertTemplateForm;
use App\Filament\Dashboard\Resources\Locations\Schemas\LocationForm;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Settings\TierThresholdSettings;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Exists;
use Jbuitt\FilamentToggleList\ToggleList;

class AlertRuleForm
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
            self::categoryStep(),
            self::detailsStep(),
            self::channelsStep(),
            self::quietHoursStep(),
        ];
    }

    protected static function categoryStep(): Step
    {
        return Step::make('Category')
            ->schema([
                Radio::make('category')
                    ->hiddenLabel()
                    ->options(collect(AlertCategory::cases())->mapWithKeys(fn (AlertCategory $c) => [$c->value => $c->label()]))
                    ->descriptions(collect(AlertCategory::cases())->mapWithKeys(fn (AlertCategory $c) => [$c->value => $c->description()]))
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, Get $get): void {
                        self::seedProductIdsDefaults($set, $get);
                        self::seedForecastDiscussionWfoDefault($set, $get);
                        self::clearAlertTemplateIfCategoryUnsupported($set, $get);
                    }),
            ]);
    }

    /**
     * `product_ids.{category}` fields only enter the schema once `category`
     * is picked (detailsStep()'s schema is a `fn (Get $get) => match(...)`
     * closure) — they don't exist at the wizard's initial mount, so
     * Filament's normal fill()-time default() application never runs for
     * them. Left alone, each starts out `null` rather than `[]` the first
     * time its ToggleList renders, and multiple checkboxes sharing one
     * wire:model path whose current value is `null` (not an array) get
     * bound by Livewire as a single boolean toggle instead of a
     * checkbox-group array — confirmed via a real browser network capture:
     * checking one box produced `product_ids.tornado_and_severe_thunderstorm
     * = true`, not `[<id>]`, with every untouched sibling category left as
     * `null`. Explicitly seeding every category to a real `[]` here, before
     * the checkboxes ever render for the first time, fixes the binding at
     * its source. Guarded on the current value already being `null` so
     * switching categories back and forth never wipes a real selection.
     */
    protected static function seedProductIdsDefaults(Set $set, Get $get): void
    {
        foreach (ProductCategory::cases() as $category) {
            if ($get("product_ids.{$category->value}") === null) {
                $set("product_ids.{$category->value}", []);
            }
        }
    }

    /**
     * `wfo` (forecastDiscussionFields()'s field) hits the exact same timing
     * issue as product_ids above: it only enters the schema once `category`
     * is picked, so a plain ->default() on the field itself never fires
     * (confirmed via a control test: radius_miles's pre-existing
     * ->default(15) in lightningFields() — same dynamic-step context —
     * also never applies). Seeded here the same way instead, guarded on
     * the field currently being blank so switching categories away and
     * back, or typing over the suggestion, never clobbers a real value.
     */
    protected static function seedForecastDiscussionWfoDefault(Set $set, Get $get): void
    {
        if ($get('category') === AlertCategory::ForecastDiscussion->value && blank($get('wfo'))) {
            $set('wfo', self::suggestedWfo());
        }
    }

    /**
     * alert_template_id's own field is hidden (not just disabled) once the
     * category changes away from weather_alerts/spc_wpc, and a hidden
     * field is neither dehydrated nor validated by default -- so without
     * this, a stale id from a prior category selection would silently
     * survive the save and leak into DeliverScheduledAlert's
     * RenderAlertSubject call for a category that's supposed to never
     * have one attached.
     */
    protected static function clearAlertTemplateIfCategoryUnsupported(Set $set, Get $get): void
    {
        if (! in_array($get('category'), [AlertCategory::WeatherAlerts->value, AlertCategory::SpcWpc->value], true)) {
            $set('alert_template_id', null);
        }
    }

    protected static function detailsStep(): Step
    {
        return Step::make('Set it up')
            ->schema(fn (Get $get): array => match (AlertCategory::tryFrom($get('category'))) {
                AlertCategory::WeatherAlerts => self::weatherAlertsFields(),
                AlertCategory::SpcWpc => self::spcWpcFields(),
                AlertCategory::Threshold => self::thresholdFields(),
                AlertCategory::DailySummary => self::dailySummaryFields(),
                AlertCategory::Lightning => self::lightningFields(),
                AlertCategory::ForecastDiscussion => self::forecastDiscussionFields(),
                null => [],
            });
    }

    protected static function channelsStep(): Step
    {
        return Step::make('Notify me on')
            ->schema([
                self::channelsSelect(),
            ]);
    }

    /**
     * Multiple, not CheckboxList: unlike product_ids/location_id, this field
     * lives in a STATIC step schema (channelsStep() above is a plain array,
     * not a Step::schema(fn (Get $get) => ...) closure) — it exists on every
     * render from the wizard's first mount onward, so it doesn't hit the
     * entangle bug documented on locationSelect()/productSections().
     * That's what makes it safe to use Select::multiple() here.
     *
     * One suffix action per channel type, not a single createOptionForm()
     * with an internal type picker: a Select action's modal is one flat
     * schema, and Filament doesn't reliably validate a field that's only
     * conditionally required based on another field in that same schema —
     * confirmed via a failing test where a required() closure gated on
     * $get('channel_type') still rejected the correct, present value as
     * missing. Each suffix action's modal is a fixed, unconditional schema
     * (exactly one type's formSchema()), which sidesteps that entirely.
     */
    protected static function channelsSelect(): Select
    {
        $registry = app(ChannelTypeRegistry::class);

        return Select::make('channel_ids')
            ->label('Channels')
            ->multiple()
            ->required()
            ->options(fn (): array => auth()->user()->notificationChannels()->get()
                ->mapWithKeys(fn (NotificationChannel $channel): array => [
                    $channel->id => $channel->isVerified() ? $channel->label : "{$channel->label} (unverified)",
                ])
                ->all())
            ->helperText('A channel can\'t actually deliver until it\'s verified — add one here and finish confirming it from Notification Channels.')
            ->suffixActions($registry->forPicker()
                ->filter(fn (array $row): bool => $row['type']->supportsInlineCreate())
                ->values()
                ->map(fn (array $row): Action => self::connectChannelAction($row['type']))
                ->all());
    }

    protected static function connectChannelAction(ChannelType $type): Action
    {
        $slug = $type->slug();

        return Action::make("connect_{$slug}")
            ->label($type->label())
            ->tooltip("Add a {$type->label()} channel")
            ->icon($type->icon())
            ->visible(fn (): bool => auth()->user()->canAddChannel($slug))
            ->schema($type->formSchema())
            ->modalHeading("Add a {$type->label()} channel")
            ->action(function (array $data, Set $set, Get $get) use ($type, $slug): void {
                $destination = $type->buildDestination($data);

                $channel = auth()->user()->notificationChannels()->create([
                    'channel_type' => $slug,
                    'label' => $destination['label'] ?? $type->label(),
                    ...Arr::only($destination, ['apprise_url', 'config']),
                ]);

                app(DispatchChannelConfirmationCode::class)($channel);

                $set('channel_ids', [...($get('channel_ids') ?? []), $channel->getKey()]);
            });
    }

    protected static function quietHoursStep(): Step
    {
        return Step::make('Finishing touches')
            ->schema([
                TextInput::make('name')
                    ->label('Name this alert (optional)')
                    ->maxLength(255)
                    ->helperText('Leave blank and we\'ll generate one from your settings.'),
                TimePicker::make('quiet_hours_start')
                    ->label('Quiet hours start')
                    ->seconds(false),
                TimePicker::make('quiet_hours_end')
                    ->label('Quiet hours end')
                    ->seconds(false)
                    ->helperText('Optional — skip notifications for this alert during a window each day.'),
                Select::make('message_body_style')
                    ->label('Message length')
                    ->options(collect(MessageBodyStyle::cases())->mapWithKeys(fn (MessageBodyStyle $s) => [$s->value => $s->label()]))
                    ->default(MessageBodyStyle::Full)
                    ->visible(fn (): bool => auth()->user()->tier() !== Tier::Free),
                TextInput::make('custom_subject_template')
                    ->label('Custom subject line (optional)')
                    ->maxLength(255)
                    ->helperText('Leave blank to use the default. Placeholders: {product}, {location}, {category}.')
                    ->visible(fn (): bool => auth()->user()->tier() !== Tier::Free),
                self::alertTemplateSelect()
                    ->visible(fn (Get $get): bool => auth()->user()->tier() !== Tier::Free
                        && in_array($get('category'), [AlertCategory::WeatherAlerts->value, AlertCategory::SpcWpc->value], true)),
            ]);
    }

    /**
     * Deliberately native, not ->searchable()/->relationship() -- same
     * dynamic-Wizard-step entangle bug documented on locationSelect() and
     * wfoField() above applies here too, since this field's ->visible()
     * closure depends on `category`, which is set in an earlier step.
     * Additive alongside message_body_style/custom_subject_template above,
     * not a replacement -- see this field's helperText.
     */
    protected static function alertTemplateSelect(): Select
    {
        return Select::make('alert_template_id')
            ->label('Message template (optional)')
            ->options(fn (Get $get): array => auth()->user()->alertTemplates()
                ->where('category', $get('category'))
                ->pluck('name', 'id')
                ->all())
            ->createOptionForm(AlertTemplateForm::fields())
            ->createOptionAction(fn (Action $action): Action => $action->modalHeading('Add a template'))
            ->createOptionUsing(fn (array $data): int => auth()->user()->alertTemplates()->create($data)->getKey())
            ->helperText(fn (Get $get): string => $get('category') === AlertCategory::WeatherAlerts->value
                ? 'Takes priority over the subject line and message length above when set. Leave blank to keep using those.'
                : 'Takes priority over the subject line above when set. Leave blank to keep using that.');
    }

    /**
     * @return array<Component>
     */
    protected static function weatherAlertsFields(): array
    {
        return [
            ...self::productSections(Product::query()
                ->whereIn('family', ['warning', 'watch', 'advisory', 'statement', 'informational', 'administrative', 'aviation'])
                ->where('status', 'active')),
            ...self::matchingModeFields(AlertCategory::WeatherAlerts),
        ];
    }

    /**
     * @return array<Component>
     */
    protected static function spcWpcFields(): array
    {
        return [
            ...self::productSections(Product::query()
                ->where('family', 'spc_wpc')
                ->where('status', 'active')),
            CheckboxList::make('risk_levels')
                ->label('Risk levels')
                ->columns(2)
                ->options(fn (Get $get): array => Product::query()
                    ->whereIn('id', self::selectedProductIds($get))
                    ->whereNotNull('risk_level_options')
                    ->get()
                    ->pluck('risk_level_options')
                    ->flatten()
                    ->unique()
                    ->mapWithKeys(fn (string $level): array => [$level => $level])
                    ->all())
                ->helperText('Leave all unchecked to receive every risk level for the products above.')
                ->visible(fn (Get $get): bool => self::hasRiskLevelProducts($get)),
            ...self::matchingModeFields(AlertCategory::SpcWpc),
        ];
    }

    /**
     * Shared by the risk_levels CheckboxList's own visibility above and
     * matchingModeFields()'s SPC/WPC option set below, so both stay in
     * lockstep: a rule only ever sees the RiskArea matching mode when it
     * would also see the risk-level checkboxes that mode's threshold reads
     * from.
     */
    protected static function hasRiskLevelProducts(Get $get): bool
    {
        return Product::query()
            ->whereIn('id', self::selectedProductIds($get))
            ->whereNotNull('risk_level_options')
            ->exists();
    }

    /**
     * Renders the given product query as one collapsible Section per
     * ProductCategory present in the results, each holding a ToggleList
     * (jbuitt/filament-toggle-list — a CheckboxList-alike that renders
     * genuine toggle switches instead of checkboxes, right down to using
     * plain wire:model on a real <input type="checkbox">, same as
     * CheckboxList — see locationSelect()'s note on the dynamic-Wizard-step
     * entangle bug; that's what makes it safe here too) of that category's
     * products with their descriptions shown inline.
     *
     * Each ToggleList is named `product_ids.{category}` rather than a
     * single flat `product_ids` — Filament stores this as a nested array
     * (`product_ids => ['warning' => [1, 2], 'watch' => [3]]`), so every
     * category keeps its own field/state without a manual merge step. Callers
     * needing the flat list of selected IDs (risk_levels above, and the
     * Create/Edit pages' pivot sync) use selectedProductIds()/flattening.
     *
     * Sections start collapsed unless they already hold a selection (so
     * editing an existing rule doesn't hide what's checked), except Tornado
     * and Severe Thunderstorm, which always starts expanded — it's the most
     * commonly used category, so it shouldn't need an extra click on a
     * fresh Create.
     *
     * @return array<Section>
     */
    protected static function productSections(Builder $query): array
    {
        $productsByCategory = $query->orderBy('sort_order')->get(['id', 'name', 'description', 'category'])
            ->groupBy(fn (Product $product): ?string => $product->category?->value);

        return collect(ProductCategory::cases())
            ->filter(fn (ProductCategory $category): bool => $productsByCategory->has($category->value))
            ->values()
            ->map(function (ProductCategory $category, int $index) use ($productsByCategory): Section {
                $fieldName = "product_ids.{$category->value}";
                $products = $productsByCategory->get($category->value);

                $toggleList = ToggleList::make($fieldName)
                    ->hiddenLabel()
                    ->searchable()
                    ->bulkToggleable()
                    ->columns(1)
                    ->live()
                    ->options($products->pluck('name', 'id'))
                    ->descriptions($products->pluck('description', 'id'));

                // Only the first section carries the "at least one product"
                // check — it validates the flattened total across every
                // section, so attaching it to each one would just repeat the
                // same error under every category.
                if ($index === 0) {
                    $toggleList = $toggleList->rules([
                        fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if (self::selectedProductIds($get) === []) {
                                $fail('Select at least one product.');
                            }
                        },
                    ]);
                }

                return Section::make($category->label())
                    ->icon($category->icon())
                    ->collapsible()
                    ->collapsed(fn (Get $get): bool => $category !== ProductCategory::TornadoAndSevereThunderstorm
                        && self::selectedProductIds($get, $fieldName) === [])
                    ->schema([$toggleList]);
            })
            ->all();
    }

    /**
     * @return array<int>
     */
    protected static function selectedProductIds(Get $get, ?string $onlyPath = null): array
    {
        if ($onlyPath !== null) {
            return collect($get($onlyPath))->filter(fn ($id): bool => is_numeric($id))->values()->all();
        }

        return collect($get('product_ids'))->flatten()->filter(fn ($id): bool => is_numeric($id))->unique()->values()->all();
    }

    /**
     * Weather Alerts gets all 5 modes, unchanged. SPC/WPC never offers
     * Location/WFO/County-Zone at all -- SPC/WPC always issues from the
     * same two offices nationwide, so they're meaningless there -- and
     * swaps Polygon for RiskArea (or keeps Polygon) depending on whether
     * the products selected in the step above actually carry categorical
     * risk levels (see hasRiskLevelProducts()): RiskArea's threshold reads
     * risk_levels, which only exists for those products, while a plain
     * event-type SPC/WPC product (Mesoscale Discussion, WPC MPD) has a
     * real single polygon of its own that Polygon mode already matches
     * correctly. Known minor gap: switching which products are selected
     * within the SAME spc_wpc rule, after matching_mode is already set,
     * can leave a no-longer-offered mode selected with nothing shown
     * checked -- narrow enough (it needs a product-set change, not just a
     * category change, which existing dynamic-step patterns already don't
     * carry state across) not to chase further here.
     *
     * @return array<Component>
     */
    protected static function matchingModeFields(AlertCategory $category): array
    {
        $allowedModes = fn (Get $get): array => match ($category) {
            AlertCategory::SpcWpc => self::hasRiskLevelProducts($get)
                ? [MatchingMode::Nationwide, MatchingMode::RiskArea]
                : [MatchingMode::Nationwide, MatchingMode::Polygon],
            // RiskArea's threshold reads risk_levels, which only Weather
            // Alerts' sibling spc_wpc branch above ever populates -- every
            // other mode is fair game here.
            default => array_values(array_filter(
                MatchingMode::cases(),
                fn (MatchingMode $m): bool => $m !== MatchingMode::RiskArea,
            )),
        };

        return [
            Radio::make('matching_mode')
                ->label('Matching mode')
                ->options(fn (Get $get): array => collect($allowedModes($get))->mapWithKeys(fn (MatchingMode $m) => [$m->value => $m->label()])->all())
                ->descriptions(fn (Get $get): array => collect($allowedModes($get))->mapWithKeys(fn (MatchingMode $m) => [$m->value => $m->helperText()])->all())
                ->required()
                ->live(),
            self::locationsSelect()
                ->visible(fn (Get $get): bool => in_array($get('matching_mode'), [MatchingMode::Location->value, MatchingMode::Polygon->value, MatchingMode::RiskArea->value], true)),
            self::wfoField()
                ->visible(fn (Get $get): bool => $get('matching_mode') === MatchingMode::Wfo->value),
            self::countyZoneStateField()
                ->visible(fn (Get $get): bool => $get('matching_mode') === MatchingMode::CountyZone->value),
            self::countyZoneField()
                ->visible(fn (Get $get): bool => $get('matching_mode') === MatchingMode::CountyZone->value),
        ];
    }

    /**
     * @return array<Component>
     */
    protected static function thresholdFields(): array
    {
        return [
            Select::make('variable')
                ->options(collect(ThresholdVariable::cases())->mapWithKeys(fn (ThresholdVariable $v) => [$v->value => $v->label()]))
                ->required()
                ->live(),
            Select::make('direction')
                ->options(collect(ThresholdDirection::cases())->mapWithKeys(fn (ThresholdDirection $d) => [$d->value => $d->label()]))
                ->required(),
            TextInput::make('threshold_value')
                ->numeric()
                ->required()
                ->suffix(fn (Get $get): ?string => ThresholdVariable::tryFrom($get('variable'))?->unit()),
            Select::make('timeframe')
                ->options(collect(ThresholdTimeframe::cases())->mapWithKeys(fn (ThresholdTimeframe $t) => [$t->value => $t->label()]))
                ->required(),
            self::locationSelect(),
            TimePicker::make('delivery_time')
                ->required()
                ->seconds(false)
                ->helperText('When we check and deliver this alert each day.'),
        ];
    }

    /**
     * @return array<Component>
     */
    protected static function dailySummaryFields(): array
    {
        return [
            self::locationSelect(),
            TimePicker::make('delivery_time')
                ->required()
                ->seconds(false),
        ];
    }

    /**
     * @return array<Component>
     */
    protected static function lightningFields(): array
    {
        return [
            self::locationSelect(),
            TextInput::make('radius_miles')
                ->label('Alert radius (miles)')
                ->numeric()
                ->minValue(fn (): int => app(TierThresholdSettings::class)->lightning_min_radius_miles)
                ->maxValue(50)
                ->default(15)
                ->required()
                ->helperText(fn (): string => 'Minimum '.app(TierThresholdSettings::class)->lightning_min_radius_miles.' miles — tighter radii outrun what satellite lightning detection can reliably pinpoint.'),
        ];
    }

    /**
     * @return array<Component>
     */
    protected static function forecastDiscussionFields(): array
    {
        return [
            // No ->default() here: this field only enters the schema once
            // category is picked, so a bare default() never fires -- see
            // seedForecastDiscussionWfoDefault(), which sets it eagerly
            // from categoryStep()'s afterStateUpdated() hook instead.
            self::wfoField()
                ->helperText('Search by office code or by city/state if you\'re not sure which is yours.'),
        ];
    }

    /**
     * Suggests a WFO from the subscriber's most recently added Location via
     * a real ST_Contains lookup against cwa_boundaries -- a convenience
     * default only (the subscriber can still type over it), since this
     * wizard step has no location picker of its own for ForecastDiscussion
     * rules.
     */
    protected static function suggestedWfo(): ?string
    {
        $location = auth()->user()->locations()->latest()->first();

        if ($location === null) {
            return null;
        }

        $row = DB::selectOne(
            'SELECT cwa FROM cwa_boundaries WHERE ST_Contains(boundary, ST_SetSRID(ST_MakePoint(?, ?), 4269)) LIMIT 1',
            [(float) $location->lng, (float) $location->lat],
        );

        return $row?->cwa;
    }

    /**
     * Deliberately native (no ->searchable()/->relationship()): Select's
     * non-native mode renders a choices.js widget wired up via
     * $wire.$entangle(), which errors ("cannot be found on component") for
     * any field that only exists inside this Wizard step's dynamically-built
     * schema (Step::schema(fn (Get $get) => ...) — the field object is only
     * constructed once the category is known, not present at initial mount
     * like a static schema's fields are). A native <select wire:model>
     * sidesteps the entangle path entirely.
     *
     * Threshold/DailySummary/Lightning only -- these categories each pick a
     * single location directly (no matching_mode concept), so they keep
     * this singular location_id field. See locationsSelect() below for the
     * Weather Alerts/SPC & WPC multi-location field.
     */
    protected static function locationSelect(): Select
    {
        return Select::make('location_id')
            ->label('Location')
            ->options(fn (): array => auth()->user()->locations()->pluck('label', 'id')->all())
            ->required()
            ->exists('locations', 'id', fn (Exists $rule): Exists => $rule->where('user_id', auth()->id()))
            ->createOptionForm(LocationForm::fields())
            ->createOptionAction(fn (Action $action): Action => $action
                ->visible(fn (): bool => auth()->user()->canCreateLocation())
                ->modalHeading('Add a location'))
            ->createOptionUsing(fn (array $data): int => auth()->user()->locations()->create($data)->getKey())
            ->helperText(fn (): ?string => match (true) {
                auth()->user()->locations()->exists() => null,
                auth()->user()->canCreateLocation() => 'No saved locations yet — use the + button to add one.',
                default => 'You\'ve reached your location limit — remove one or upgrade to add another.',
            });
    }

    /**
     * Weather Alerts/SPC & WPC only -- Threshold/DailySummary/Lightning
     * keep the separate singular location_id field (daily summary/
     * threshold/lightning fields aren't built through this method).
     * ->multiple() is safe in this dynamic Step::schema(fn (Get $get) =>
     * ...) context because options() below is a plain closure, not
     * ->relationship() -- see countyZoneField()'s docblock in this same
     * file, proven safe here in a real browser already for exactly this
     * reason. ->exists() applied directly to this multiple() field's array
     * value works with no extra wiring: Laravel's own validateExists()
     * (Illuminate\Validation\Concerns\ValidatesAttributes) counts unique
     * values in the submitted array and requires that many matches from
     * the scoped query, so a single id belonging to another subscriber
     * fails the whole field -- verified by reading that method directly,
     * not assumed, and covered by "a subscriber cannot attach another
     * subscriber's location via location_ids" below.
     */
    protected static function locationsSelect(): Select
    {
        return Select::make('location_ids')
            ->label('Locations')
            ->multiple()
            ->options(fn (): array => auth()->user()->locations()->pluck('label', 'id')->all())
            ->required()
            ->exists('locations', 'id', fn (Exists $rule): Exists => $rule->where('user_id', auth()->id()))
            ->createOptionForm(LocationForm::fields())
            ->createOptionAction(fn (Action $action): Action => $action
                ->visible(fn (): bool => auth()->user()->canCreateLocation())
                ->modalHeading('Add a location'))
            ->createOptionUsing(fn (array $data): int => auth()->user()->locations()->create($data)->getKey())
            ->helperText(fn (): ?string => match (true) {
                auth()->user()->locations()->exists() => null,
                auth()->user()->canCreateLocation() => 'No saved locations yet — use the + button to add one.',
                default => 'You\'ve reached your location limit — remove one or upgrade to add another.',
            });
    }

    /**
     * A real picker, not free text: options come from cwa_boundaries (125
     * real rows, one per WFO, from the AWIPS Shapefile Management feature),
     * keyed on the natural `cwa` code (already the exact 3-letter identifier
     * this app compares against IngestedProduct.cccc elsewhere) and labeled
     * with the office's real city/state for a subscriber who doesn't have
     * their local office's code memorized. Shared by both call sites (the
     * Wfo matching-mode field and Forecast Discussion's office field) --
     * this is the same field as locationSelect()'s note below now describes
     * an exception for; see that docblock for why ->searchable() is safe
     * here despite this file's documented dynamic-step entangle bug.
     */
    protected static function wfoField(): Select
    {
        return Select::make('wfo')
            ->label('NWS office (WFO)')
            ->searchable()
            ->required()
            ->options(fn (): array => DB::table('cwa_boundaries')->orderBy('cwa')->get(['cwa', 'citystate'])
                ->mapWithKeys(fn ($row): array => [$row->cwa => "{$row->cwa} — {$row->citystate}"])
                ->all());
    }

    /**
     * These fields use ->searchable()/->multiple() inside this same dynamic
     * Step::schema(fn (Get $get) => ...) context that locationSelect()
     * deliberately avoids those on, per this file's own documented Livewire
     * entangle bug (see .ai/rules/filament.md). That bug doesn't apply here
     * (or to wfoField() above): options() is a plain closure resolved and
     * embedded server-side at render time (no relationship()/live AJAX
     * round-trip), which is what the entangle failure mode actually depends
     * on. Verified directly in a real browser (not just Pest's fillForm(),
     * which sets Livewire state directly and can't detect an entangle
     * desync): Create, and Edit-then-Save on an existing CountyZone rule,
     * both hydrate and persist the real stored codes correctly with no
     * console errors.
     */
    protected static function countyZoneStateField(): Select
    {
        return Select::make('county_zone_state')
            ->label('Filter by state')
            ->dehydrated(false)
            ->live()
            ->searchable()
            ->options(fn (): array => DB::table('public_zones')->distinct()->orderBy('state')->pluck('state', 'state')->all())
            ->helperText('Narrows the list below to one state at a time — the codes you pick can still span multiple states.');
    }

    protected static function countyZoneField(): Select
    {
        return Select::make('county_zone_codes')
            ->label('Counties / zones (UGC)')
            ->multiple()
            ->searchable()
            ->required()
            ->options(fn (Get $get): array => self::countyZoneOptions($get('county_zone_state'), self::currentCountyZoneCodes($get)))
            ->afterStateHydrated(function (Select $component, mixed $state): void {
                $component->state(is_string($state) && $state !== '' ? explode(',', $state) : ($state ?? []));
            })
            ->dehydrateStateUsing(fn (?array $state): ?string => filled($state) ? implode(',', $state) : null)
            ->helperText('Pick the counties/forecast zones this alert should watch.');
    }

    /**
     * @return array<int, string>
     */
    protected static function currentCountyZoneCodes(Get $get): array
    {
        $codes = $get('county_zone_codes');

        return is_array($codes) ? $codes : [];
    }

    /**
     * counties.fips is a raw 5-digit FIPS code and public_zones.state_zone
     * omits the type letter (e.g. "NY001", not "NYZ001") -- neither is
     * itself a real UGC code, so both are derived here from state + the
     * zone/fips columns, matching ResolveLocationZones's derivation.
     *
     * @return array<string, string>
     */
    protected static function countyZoneOptions(?string $state, array $currentCodes): array
    {
        $states = collect($currentCodes)
            ->map(fn (string $code): string => substr($code, 0, 2))
            ->push($state)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($states === []) {
            return [];
        }

        $countyOptions = DB::table('counties')->whereIn('state', $states)->get(['fips', 'state', 'countyname'])
            ->mapWithKeys(function ($c): array {
                $code = strtoupper($c->state).'C'.substr($c->fips, -3);

                return [$code => "{$code} — {$c->countyname} County, {$c->state}"];
            });

        $zoneOptions = DB::table('public_zones')->whereIn('state', $states)->get(['state_zone', 'state', 'zone', 'name'])
            ->mapWithKeys(function ($z): array {
                $code = strtoupper($z->state).'Z'.$z->zone;

                return [$code => "{$code} — {$z->name}, {$z->state}"];
            });

        return $countyOptions->union($zoneOptions)->all();
    }
}
