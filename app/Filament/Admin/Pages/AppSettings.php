<?php

namespace App\Filament\Admin\Pages;

use App\Settings\FeatureFlagsSettings;
use App\Settings\GeneralSettings;
use App\Settings\TierThresholdSettings;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class AppSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.admin.pages.app-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'App settings';

    protected static ?string $title = 'App settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->is_staff === true;
    }

    public function mount(): void
    {
        $this->form->fill([
            ...app(GeneralSettings::class)->toArray(),
            ...app(TierThresholdSettings::class)->toArray(),
            ...app(FeatureFlagsSettings::class)->toArray(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('Settings')
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('General')->schema([
                            Section::make('Maintenance mode')
                                ->description('Puts the subscriber dashboard into a read-only state with a banner. Ingestion and alert delivery keep running.')
                                ->schema([
                                    Toggle::make('maintenance_mode')->label('Maintenance mode'),
                                    Textarea::make('maintenance_message')
                                        ->label('Banner message')
                                        ->rows(2)
                                        ->maxLength(500)
                                        ->helperText('Optional. Leave empty for the default message.'),
                                ]),
                        ]),
                        Tab::make('Tier & threshold defaults')->schema([
                            Section::make('Locations')->columns(2)->schema([
                                $this->count('free_locations', 'Free locations', 1, 10),
                                $this->count('plus_locations', 'Plus locations', 1, 100)->gte('free_locations'),
                            ])->description('Pro locations are unlimited.'),
                            Section::make('Notification channels')->schema([
                                $this->count('free_no_cost_channels', 'Free no-cost channels', 0, 5),
                            ])->description('Email is unlimited at every tier; Plus and Pro have no channel limit.'),
                            Section::make('Traccar servers')->columns(3)->schema([
                                $this->count('free_traccar_servers', 'Free', 0, 20),
                                $this->count('plus_traccar_servers', 'Plus', 0, 20)->gte('free_traccar_servers'),
                                $this->count('pro_traccar_servers', 'Pro', 0, 20)->gte('plus_traccar_servers'),
                            ]),
                            Section::make('Traccar devices per server')->columns(3)->schema([
                                $this->count('free_traccar_devices_per_server', 'Free', 0, 100),
                                $this->count('plus_traccar_devices_per_server', 'Plus', 0, 100)->gte('free_traccar_devices_per_server'),
                                $this->count('pro_traccar_devices_per_server', 'Pro', 0, 100)->gte('plus_traccar_devices_per_server'),
                            ]),
                            Section::make('Thresholds')->schema([
                                $this->count('lightning_min_radius_miles', 'Lightning alert minimum radius (miles)', 5, 15)
                                    ->helperText('Applies when a Lightning rule is created or edited; existing rules keep their stored radius.'),
                            ]),
                        ]),
                        Tab::make('Feature flags')->schema([
                            Section::make('Heads Up')
                                ->description('An emergency off switch for the predictive Heads Up alerts. Turning it off hides Heads Up dashboard-wide and stops it from firing, but keeps each subscriber\'s per-location opt-ins and disclaimer acknowledgments, so turning it back on restores them without anyone re-confirming. This is independent of the Plus/Pro entitlement that gates who can use it.')
                                ->schema([
                                    Toggle::make('heads_up_enabled')->label('Heads Up enabled'),
                                ]),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $data['maintenance_message'] = filled($data['maintenance_message'] ?? null) ? $data['maintenance_message'] : null;

        DB::transaction(function () use ($data): void {
            foreach ([GeneralSettings::class, TierThresholdSettings::class, FeatureFlagsSettings::class] as $class) {
                $settings = app($class);
                $settings->fill(Arr::only($data, array_keys($settings->toArray())));
                $settings->save();
            }
        });

        Notification::make()->success()->title('Settings saved')->send();
    }

    private function count(string $name, string $label, int $min, int $max): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->numeric()
            ->integer()
            ->minValue($min)
            ->maxValue($max)
            ->required();
    }
}
