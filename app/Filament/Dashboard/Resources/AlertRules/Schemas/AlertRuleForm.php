<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Schemas;

use App\Actions\Alerts\SaveAlertRule;
use App\Actions\Channels\DispatchChannelConfirmationCode;
use App\Enums\AlertCategory;
use App\Enums\MessageBodyStyle;
use App\Enums\OutlookReportMode;
use App\Enums\ProductCategory;
use App\Enums\ThresholdDirection;
use App\Enums\ThresholdTimeframe;
use App\Enums\ThresholdVariable;
use App\Enums\Tier;
use App\Filament\Dashboard\Resources\AlertTemplates\Schemas\AlertTemplateForm;
use App\Filament\Dashboard\Resources\Locations\Schemas\LocationForm;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Settings\TierThresholdSettings;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\ChannelType;
use App\Support\Timezones;
use App\Support\Units;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Exists;
use Throwable;

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

    /**
     * The outlook report is a paid-tier feature: a Free subscriber sees it disabled.
     */
    private static function isPaidOnlyForUser(?AlertCategory $category): bool
    {
        return $category === AlertCategory::OutlookReport && auth()->user()->tier() === Tier::Free;
    }

    protected static function categoryStep(): Step
    {
        return Step::make('Category')
            ->schema([
                Radio::make('category')
                    ->hiddenLabel()
                    ->options(collect(AlertCategory::cases())->mapWithKeys(fn (AlertCategory $c) => [$c->value => $c->label()]))
                    ->descriptions(fn (): array => collect(AlertCategory::cases())->mapWithKeys(fn (AlertCategory $c) => [
                        $c->value => self::isPaidOnlyForUser($c) ? $c->description().' '.SaveAlertRule::PAID_ONLY_MESSAGE : $c->description(),
                    ])->all())
                    // A disabled option is also refused on the server (Radio's `in` rule lists only enabled options).
                    ->disableOptionWhen(fn (string $value): bool => self::isPaidOnlyForUser(AlertCategory::tryFrom($value)))
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, Get $get, ?AlertRule $record): void {
                        self::seedProductSettings($set, $get, $record);
                        self::clearAlertTemplateIfCategoryUnsupported($set, $get);
                        self::seedOutlookReportDefaults($set, $get);
                    }),
            ]);
    }

    /**
     * The product tables (`product_settings.{category}`) only enter the schema once `category` is
     * picked (detailsStep()'s schema is a `fn (Get $get) => match(...)` closure), so field
     * defaults never apply to them: every row of the chosen alert category is seeded here, before
     * the table first renders (see ProductMatchingTable::formState()). Choosing a category replaces
     * `product_settings` wholesale with that category's catalog: on Create, unsaved picks are lost when
     * the category is switched, except the rows of a product both categories list (Weather Alerts and
     * SPC & WPC share some product-category keys); on Edit the rule's watched products come back with
     * their stored settings.
     *
     * The per-category `risk_levels.{category}` checklists are seeded to `[]`: multiple
     * checkboxes sharing one wire:model path whose value is `null` bind as a single boolean
     * instead of an array. Guarded on `null` so a real selection is never wiped.
     */
    protected static function seedProductSettings(Set $set, Get $get, ?AlertRule $record): void
    {
        $category = AlertCategory::tryFrom((string) $get('category'));

        if (ProductMatchingTable::supports($category)) {
            $set('product_settings', ProductMatchingTable::formState(auth()->user(), $category, $record, $get('product_settings')));
        }

        foreach (ProductCategory::cases() as $productCategory) {
            if ($get("risk_levels.{$productCategory->value}") === null) {
                $set("risk_levels.{$productCategory->value}", []);
            }
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

    /**
     * The outlook report's step is built dynamically, so its field ->default()s never apply (see
     * .ai/rules/filament.md): they are seeded here when the category is chosen, guarded on null so a
     * value already picked (or loaded on Edit) is never replaced. `location_ids` is seeded to []
     * so the checkboxes bind as an array.
     */
    protected static function seedOutlookReportDefaults(Set $set, Get $get): void
    {
        if ($get('category') !== AlertCategory::OutlookReport->value) {
            return;
        }

        foreach (['delivery_time' => '14:30', 'report_mode' => OutlookReportMode::EveryDay->value, 'location_ids' => []] as $key => $default) {
            if ($get($key) === null) {
                $set($key, $default);
            }
        }
    }

    protected static function detailsStep(): Step
    {
        return Step::make('Set it up')
            ->schema(function (Get $get, ?AlertRule $record): array {
                $fields = match ($category = AlertCategory::tryFrom($get('category'))) {
                    // A retired product joins the table only when the edited rule watches it (never from form state).
                    AlertCategory::WeatherAlerts, AlertCategory::SpcWpc => ProductMatchingTable::sections($category, $record?->products->pluck('id')->all() ?? []),
                    AlertCategory::Threshold => self::thresholdFields(),
                    AlertCategory::DailySummary => self::dailySummaryFields(),
                    AlertCategory::Lightning => self::lightningFields(),
                    AlertCategory::OutlookReport => self::outlookReportFields(),
                    null => [],
                };

                return $fields === [] ? [] : [...$fields, View::make('filament.dashboard.alert-rules.scroll-to-bottom')];
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
     * Multiple, not CheckboxList: unlike location_id, this field
     * lives in a STATIC step schema (channelsStep() above is a plain array,
     * not a Step::schema(fn (Get $get) => ...) closure) — it exists on every
     * render from the wizard's first mount onward, so it doesn't hit the
     * entangle bug documented on locationSelect().
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
                    ->helperText(fn (Get $get): ?string => $get('category') === AlertCategory::OutlookReport->value
                        ? 'Brief sends the short circle summary to every channel; Full also sends the detailed email to email channels.'
                        : null)
                    ->visible(fn (): bool => auth()->user()->tier() !== Tier::Free),
                TextInput::make('custom_subject_template')
                    ->label('Custom subject line (optional)')
                    ->maxLength(255)
                    ->helperText('Leave blank to use the default. Placeholders: {product}, {location}, {category}, {until}, {states}, {wfo}.')
                    ->visible(fn (): bool => auth()->user()->tier() !== Tier::Free),
                self::alertTemplateSelect()
                    ->visible(fn (Get $get): bool => auth()->user()->tier() !== Tier::Free
                        && in_array($get('category'), [AlertCategory::WeatherAlerts->value, AlertCategory::SpcWpc->value], true)),
            ]);
    }

    /**
     * Deliberately native, not ->searchable()/->relationship() -- same
     * dynamic-Wizard-step entangle bug documented on locationSelect()
     * applies here too, since this field's ->visible()
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
     * A category's own "Risk levels" checklist, shown inside that category's section when
     * any product in it offers risk levels (today: Flooding, Fire Weather and Convective Outlooks).
     * State is nested per category, `risk_levels.{category}`, like `product_settings.{category}`,
     * so each checklist keeps its own state; the ticks are stored on that category's products
     * only (ProductMatchingTable::toSaveInput(), SaveAlertRule) and read back per category on
     * edit (SpcWpcRiskLevels::forForm()).
     * Options are the switched-on products' own `risk_level_options`; the list is hidden unless
     * a switched-on product in this category offers some (the switch of such a product
     * re-renders the form, see ProductMatchingTable::switchRefreshesForm()).
     *
     * The candidates are the category's SPC & WPC products of ANY status that offer risk
     * levels, not just the active ones: matching and SpcWpcRiskLevels::forForm() do not filter by
     * status either, so a rule still attached to a since-retired product keeps its checklist
     * (and its stored levels on save).
     *
     * @return array<CheckboxList>
     */
    public static function riskLevelFields(ProductCategory $category): array
    {
        $riskProducts = Product::query()
            ->where('family', 'spc_wpc')
            ->where('category', $category->value)
            ->whereNotNull('risk_level_options')
            ->get(['id', 'risk_level_options']);

        if ($riskProducts->isEmpty()) {
            return [];
        }

        $selectedRiskProducts = fn (Get $get): Collection => $riskProducts
            ->whereIn('id', self::selectedProductIds($get, $category));

        return [
            CheckboxList::make("risk_levels.{$category->value}")
                ->label('Risk levels')
                ->columns(2)
                ->options(fn (Get $get): array => $selectedRiskProducts($get)
                    ->pluck('risk_level_options')
                    ->flatten()
                    ->unique()
                    ->mapWithKeys(fn (string $level): array => [$level => $level])
                    ->all())
                ->helperText(self::riskLevelsHelperText($category))
                ->visible(fn (Get $get): bool => $selectedRiskProducts($get)->isNotEmpty()),
        ];
    }

    protected static function riskLevelsHelperText(ProductCategory $category): string
    {
        $base = 'Leave all unchecked to receive every risk level for the products above.';

        return match ($category) {
            ProductCategory::FireWeather => "{$base} Fire risk levels are saved, but they don't filter alerts yet.",
            ProductCategory::ConvectiveOutlooks => "{$base} Risk levels apply to the Day 1 Convective Outlook.",
            default => $base,
        };
    }

    /**
     * The switched-on products of one category's product table (`product_settings.{category}`).
     *
     * @return list<int>
     */
    protected static function selectedProductIds(Get $get, ProductCategory $category): array
    {
        return ProductMatchingTable::enabledProductIds($get("product_settings.{$category->value}"));
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
                ->minValue(-9999)
                ->maxValue(9999)
                ->required()
                ->suffix(fn (Get $get): ?string => ThresholdVariable::tryFrom($get('variable'))?->unit(Units::for(auth()->user())->system)),
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
     * The severe weather outlook report: a send time (UTC), whether to send every day, and the
     * saved locations to cover (none means a US-wide report). The locations are a native
     * CheckboxList, not a searchable Select, for the entangle reason documented on locationSelect();
     * only the subscriber's own locations that have a fix are offered (SaveAlertRule scopes the
     * ids to the owner again).
     *
     * @return array<Component>
     */
    public static function outlookReportFields(): array
    {
        return [
            TimePicker::make('delivery_time')
                ->label('Send time (UTC)')
                ->default('14:30')
                ->seconds(false)
                ->minutesStep(15)
                ->maxDate('22:45')
                ->required()
                ->live()
                ->helperText(fn (?string $state): string => trim(self::localSendTimeHint($state).' SPC\'s 1300 UTC update posts at about 13:00, and the report waits up to 60 minutes after your send time for it before sending what it has. The latest send time is 22:45 UTC, so that 60 minute wait for SPC\'s Day 1 update finishes before midnight UTC.')),
            Radio::make('report_mode')
                ->label('When to send')
                ->options(collect(OutlookReportMode::cases())->mapWithKeys(fn (OutlookReportMode $mode): array => [$mode->value => $mode->label()]))
                ->default(OutlookReportMode::EveryDay->value)
                ->required(),
            CheckboxList::make('location_ids')
                ->label('Locations')
                ->options(fn (): array => auth()->user()->locations()
                    ->whereNotNull('lat')
                    ->whereNotNull('lng')
                    ->orderBy('label')
                    ->pluck('label', 'id')
                    ->all())
                ->helperText('Leave empty for a US-wide report.'),
        ];
    }

    /**
     * The UTC send time in the subscriber's profile timezone, e.g. "That is 9:30 AM your time.", for
     * today's date (so daylight saving is applied); empty without a profile timezone or a time.
     */
    public static function localSendTimeHint(?string $time): string
    {
        $timezone = Timezones::forUser(auth()->user());

        if ($timezone === null || blank($time)) {
            return '';
        }

        try {
            $local = Carbon::now('UTC')->setTimeFromTimeString($time)->setTimezone($timezone);
        } catch (Throwable) {
            return '';
        }

        return 'That is '.$local->format('g:i A').' your time.';
    }

    /**
     * @return array<Component>
     */
    protected static function lightningFields(): array
    {
        return [
            self::locationSelect(),
            TextInput::make('radius_miles')
                ->label(fn (): string => 'Alert radius ('.Units::for(auth()->user())->distanceUnitName().')')
                ->numeric()
                ->minValue(fn (): int => Units::for(auth()->user())->minDistanceDisplay(app(TierThresholdSettings::class)->lightning_min_radius_miles))
                ->maxValue(fn (): int => Units::for(auth()->user())->maxDistanceDisplay(50))
                ->default(15)
                ->required()
                ->helperText(fn (): string => 'Minimum '.Units::for(auth()->user())->minDistanceDisplay(app(TierThresholdSettings::class)->lightning_min_radius_miles).' '.Units::for(auth()->user())->distanceUnitName().' — tighter radii outrun what satellite lightning detection can reliably pinpoint.'),
        ];
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
     * this singular location_id field. Weather Alerts and SPC & WPC pick
     * locations per product in ProductMatchingTable.
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
                default => 'You\'ve reached your saved-location limit — remove one or upgrade to add another.',
            });
    }

    /**
     * Every NWS office for the product table's WFO input, from cwa_boundaries (125 real rows,
     * one per WFO, from the AWIPS Shapefile Management feature), keyed on the natural `cwa`
     * code (the exact 3-letter identifier this app compares against IngestedProduct.cccc) and
     * labelled "CODE — City, ST" for a subscriber who doesn't know their office's code.
     *
     * @return array<string, string>
     */
    public static function wfoOptions(): array
    {
        return DB::table('cwa_boundaries')->orderBy('cwa')->get(['cwa', 'city', 'st', 'citystate'])
            ->mapWithKeys(fn ($row): array => [$row->cwa => filled($row->city) && filled($row->st)
                ? "{$row->cwa} — {$row->city}, {$row->st}"
                : "{$row->cwa} — {$row->citystate}"])
            ->all();
    }

    /**
     * The county/zone picker, shown in the product table's "Choose zones" modal (one row at a
     * time; see ProductMatchingTable). Searchable/multiple Selects are safe in an action modal:
     * options() is a plain closure resolved server-side, with no relationship()/AJAX entangle.
     */
    public static function countyZoneStateField(): Select
    {
        return Select::make('county_zone_state')
            ->label('Filter by state')
            ->dehydrated(false)
            ->live()
            ->searchable()
            ->options(fn (): array => DB::table('public_zones')->distinct()->orderBy('state')->pluck('state', 'state')->all())
            ->helperText('Narrows the list below to one state at a time — the codes you pick can still span multiple states.');
    }

    public static function countyZoneField(): Select
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
