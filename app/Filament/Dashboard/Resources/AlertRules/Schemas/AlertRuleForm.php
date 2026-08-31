<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Schemas;

use App\Actions\Channels\DispatchChannelConfirmationCode;
use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Enums\ProductCategory;
use App\Enums\ThresholdDirection;
use App\Enums\ThresholdTimeframe;
use App\Enums\ThresholdVariable;
use App\Filament\Dashboard\Resources\Locations\Schemas\LocationForm;
use App\Models\NotificationChannel;
use App\Models\Product;
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
                    ->live(),
            ]);
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
                $channel = auth()->user()->notificationChannels()->create([
                    'channel_type' => $slug,
                    'label' => $type->label(),
                    'apprise_url' => $type->buildAppriseUrl($data),
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
            ]);
    }

    /**
     * @return array<Component>
     */
    protected static function weatherAlertsFields(): array
    {
        return [
            ...self::productSections(Product::query()
                ->whereIn('family', ['warning', 'watch', 'advisory', 'statement'])
                ->where('status', 'active')),
            ...self::matchingModeFields(),
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
                ->visible(fn (Get $get): bool => Product::query()
                    ->whereIn('id', self::selectedProductIds($get))
                    ->whereNotNull('risk_level_options')
                    ->exists()),
            ...self::matchingModeFields(),
        ];
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
                    ->collapsible()
                    ->collapsed(fn (Get $get): bool => self::selectedProductIds($get, $fieldName) === [])
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
            return collect($get($onlyPath))->values()->all();
        }

        return collect($get('product_ids'))->flatten()->unique()->values()->all();
    }

    /**
     * @return array<Component>
     */
    protected static function matchingModeFields(): array
    {
        return [
            Radio::make('matching_mode')
                ->label('Matching mode')
                ->options(collect(MatchingMode::cases())->mapWithKeys(fn (MatchingMode $m) => [$m->value => $m->label()]))
                ->descriptions(collect(MatchingMode::cases())->mapWithKeys(fn (MatchingMode $m) => [$m->value => $m->helperText()]))
                ->required()
                ->live(),
            self::locationSelect()
                ->visible(fn (Get $get): bool => in_array($get('matching_mode'), [MatchingMode::Location->value, MatchingMode::Polygon->value], true)),
            self::wfoField()
                ->visible(fn (Get $get): bool => $get('matching_mode') === MatchingMode::Wfo->value),
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
                ->minValue(5)
                ->maxValue(50)
                ->default(15)
                ->required()
                ->helperText('Minimum 5 miles — tighter radii outrun what satellite lightning detection can reliably pinpoint.'),
        ];
    }

    /**
     * @return array<Component>
     */
    protected static function forecastDiscussionFields(): array
    {
        return [
            self::wfoField()
                ->helperText('Your local NWS office\'s 3-letter identifier. Look yours up at weather.gov if you\'re not sure.'),
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

    protected static function wfoField(): TextInput
    {
        return TextInput::make('wfo')
            ->label('NWS office (WFO)')
            ->required()
            ->length(3)
            ->rule('alpha')
            ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper($state) : null);
    }

    protected static function countyZoneField(): TextInput
    {
        return TextInput::make('county_zone_codes')
            ->label('County/zone codes (UGC)')
            ->required()
            ->placeholder('TXZ211, TXC201')
            ->helperText('Comma-separated NWS UGC codes, e.g. TXZ211 for a forecast zone or TXC201 for a county.')
            ->rule('regex:/^[A-Za-z]{2}[CZ]\d{3}(\s*,\s*[A-Za-z]{2}[CZ]\d{3})*$/')
            ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper(str_replace(' ', '', $state)) : null);
    }
}
