<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Schemas;

use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Enums\ThresholdDirection;
use App\Enums\ThresholdTimeframe;
use App\Enums\ThresholdVariable;
use App\Models\Product;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rules\Exists;

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
                Placeholder::make('no_channels')
                    ->hiddenLabel()
                    ->content(new HtmlString(
                        'You don\'t have any verified notification channels yet. <a href="'
                        .route('filament.dashboard.resources.notification-channels.index')
                        .'" class="underline">Connect one</a> first, then come back to finish this alert.'
                    ))
                    ->visible(fn (): bool => auth()->user()->notificationChannels()->whereNotNull('verified_at')->doesntExist()),
                CheckboxList::make('channel_ids')
                    ->hiddenLabel()
                    ->options(fn (): array => auth()->user()->notificationChannels()->whereNotNull('verified_at')->pluck('label', 'id')->all())
                    ->columns(2)
                    ->required()
                    ->visible(fn (): bool => auth()->user()->notificationChannels()->whereNotNull('verified_at')->exists()),
            ]);
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
            self::productCheckboxList()
                ->options(fn (): array => Product::query()
                    ->whereIn('family', ['warning', 'watch', 'advisory', 'statement'])
                    ->where('status', 'active')
                    ->orderBy('sort_order')
                    ->pluck('name', 'id')
                    ->all()),
            ...self::matchingModeFields(),
        ];
    }

    /**
     * @return array<Component>
     */
    protected static function spcWpcFields(): array
    {
        return [
            self::productCheckboxList()
                ->options(fn (): array => Product::query()
                    ->where('family', 'spc_wpc')
                    ->where('status', 'active')
                    ->orderBy('sort_order')
                    ->pluck('name', 'id')
                    ->all()),
            CheckboxList::make('risk_levels')
                ->label('Risk levels')
                ->columns(2)
                ->options(fn (Get $get): array => Product::query()
                    ->whereIn('id', $get('product_ids') ?? [])
                    ->whereNotNull('risk_level_options')
                    ->get()
                    ->pluck('risk_level_options')
                    ->flatten()
                    ->unique()
                    ->mapWithKeys(fn (string $level): array => [$level => $level])
                    ->all())
                ->helperText('Leave all unchecked to receive every risk level for the products above.')
                ->visible(fn (Get $get): bool => Product::query()
                    ->whereIn('id', $get('product_ids') ?? [])
                    ->whereNotNull('risk_level_options')
                    ->exists()),
            ...self::matchingModeFields(),
        ];
    }

    /**
     * Deliberately CheckboxList, not Select::multiple(): a multi-select
     * Select can never render natively (Filament forces its choices.js
     * widget whenever ->multiple() is set), and that widget's
     * $wire.$entangle() binding errors for a field that only exists inside
     * this Wizard step's dynamically-built schema — see the note on
     * locationSelect(). CheckboxList uses plain wire:model instead.
     */
    protected static function productCheckboxList(): CheckboxList
    {
        return CheckboxList::make('product_ids')
            ->label('Products')
            ->searchable()
            ->columns(2)
            ->required()
            ->live();
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
            ->helperText(fn (): ?string => auth()->user()->locations()->doesntExist()
                ? 'You don\'t have any saved locations yet — add one first from My Locations.'
                : null);
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
