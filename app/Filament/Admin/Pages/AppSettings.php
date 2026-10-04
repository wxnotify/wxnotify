<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Pipeline\SendTestHeartbeatPing;
use App\Enums\Secret;
use App\Settings\FeatureFlagsSettings;
use App\Settings\GeneralSettings;
use App\Settings\IntegrationSettings;
use App\Settings\SecretSettings;
use App\Settings\TierThresholdSettings;
use App\Support\ApplyEmailSettings;
use App\Support\Secrets;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
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
use Filament\Schemas\Components\Utilities\Get;
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

    private const NULLABLE_STRINGS = [
        'maintenance_message',
        'translation_endpoint',
        'short_urls_endpoint',
        'heartbeat_nwws_url',
        'heartbeat_emwin_url',
        'heartbeat_emwin_http_url',
        'archival_provider',
        'archival_endpoint',
        'archival_bucket',
        'status_page_url',
        'email_mailer',
        'email_from_address',
        'email_from_name',
    ];

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
            ...app(IntegrationSettings::class)->toArray(),
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
                            Section::make('Message prefix')
                                ->description('Prepends text to every message the system sends -- alert deliveries, channel confirmation codes, activity alerts, and account emails (verification, password reset).')
                                ->schema([
                                    Toggle::make('message_prefix_enabled')->label('Message prefix enabled')->live(),
                                    TextInput::make('message_prefix')
                                        ->label('Prefix')
                                        ->maxLength(50)
                                        ->required(fn (Get $get): bool => (bool) $get('message_prefix_enabled'))
                                        ->visible(fn (Get $get): bool => (bool) $get('message_prefix_enabled')),
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
                        $this->integrationsTab(),
                        $this->secretsTab(),
                    ]),
            ]);
    }

    public function save(): void
    {
        try {
            $data = $this->form->getState();

            foreach (self::NULLABLE_STRINGS as $key) {
                if (array_key_exists($key, $data)) {
                    $data[$key] = filled($data[$key]) ? $data[$key] : null;
                }
            }

            DB::transaction(function () use ($data): void {
                foreach ([GeneralSettings::class, TierThresholdSettings::class, FeatureFlagsSettings::class, IntegrationSettings::class] as $class) {
                    $settings = app($class);
                    $settings->fill(Arr::only($data, array_keys($settings->toArray())));
                    $settings->save();
                }

                $this->saveSecrets($data);
            });
        } finally {
            foreach (Secret::cases() as $secret) {
                $this->data[$secret->settingsProperty()] = null;
                $this->data["clear_{$secret->settingsProperty()}"] = false;
            }
        }

        Notification::make()->success()->title('Settings saved')->send();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveSecrets(array $data): void
    {
        if (auth()->user()?->is_owner !== true) {
            return;
        }

        $changes = [];

        foreach (Secret::cases() as $secret) {
            $property = $secret->settingsProperty();

            if (! empty($data["clear_{$property}"])) {
                $changes[$property] = null;
            } elseif (filled($data[$property] ?? null)) {
                $changes[$property] = trim((string) $data[$property]);
            }
        }

        if ($changes === []) {
            return;
        }

        $settings = app(SecretSettings::class);
        $settings->fill($changes);
        $settings->save();
    }

    private function secretsTab(): Tab
    {
        $fields = [];

        foreach (Secret::cases() as $secret) {
            $property = $secret->settingsProperty();

            $field = $secret === Secret::FirebaseServiceAccount
                ? Textarea::make($property)->rows(4)->autocomplete('off')->rules([fn (): Closure => $this->serviceAccountRule()])
                : TextInput::make($property)->password()->autocomplete('new-password');

            $fields[] = $field
                ->label($secret->label())
                ->helperText(fn (): string => Secrets::isStored($secret)
                    ? 'Set. Enter a new value to replace it.'
                    : 'Not set. Falls back to the .env value if one is configured.');
            $fields[] = Checkbox::make("clear_{$property}")
                ->label('Remove stored value')
                ->dehydrated()
                ->visible(fn (): bool => Secrets::isStored($secret));
        }

        return Tab::make('Secrets')
            ->visible(fn (): bool => auth()->user()?->is_owner === true)
            ->schema([
                Section::make('Credentials')
                    ->description('These credentials are stored encrypted and are never shown again after saving. Leave a field blank to keep its current value.')
                    ->schema($fields),
            ]);
    }

    private function serviceAccountRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $decoded = is_string($value) ? json_decode($value, true) : null;

            if (! is_array($decoded) || blank($decoded['client_email'] ?? null) || blank($decoded['private_key'] ?? null)) {
                $fail('Enter the service-account JSON, including client_email and private_key.');
            }
        };
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

    private function integrationsTab(): Tab
    {
        $mailers = collect(array_keys(config('mail.mailers')))
            ->diff(ApplyEmailSettings::EXCLUDED_MAILERS)
            ->mapWithKeys(fn (string $name): array => [$name => $name])
            ->all();

        $whenEnabled = fn (string $toggle): Closure => fn (Get $get): bool => (bool) $get($toggle);

        return Tab::make('Integrations')->schema([
            Section::make('Translation')
                ->description('Machine translation of alert text.')
                ->schema([
                    Toggle::make('translation_enabled')->label('Translation enabled')->live(),
                    $this->endpointField('translation_endpoint', 'Translation endpoint', 'translation_enabled'),
                ]),
            Section::make('Short URLs')
                ->schema([
                    Toggle::make('short_urls_enabled')->label('Short URLs enabled')->live(),
                    $this->endpointField('short_urls_endpoint', 'Short URL endpoint', 'short_urls_enabled'),
                ]),
            Section::make('Heartbeat')
                ->description('Healthchecks ping URLs. A feed\'s URL is requested each time that feed delivers a product; leave a URL blank to skip that feed.')
                ->schema([
                    Toggle::make('heartbeat_enabled')->label('Heartbeat enabled')->live(),
                    $this->endpointField('heartbeat_nwws_url', 'NWWS ping URL', 'heartbeat_enabled', required: false, testPing: true),
                    $this->endpointField('heartbeat_emwin_url', 'EMWIN Byteblaster ping URL', 'heartbeat_enabled', required: false, testPing: true),
                    $this->endpointField('heartbeat_emwin_http_url', 'EMWIN HTTP ping URL', 'heartbeat_enabled', required: false, testPing: true),
                ]),
            Section::make('Exception reporting')
                ->schema([
                    Toggle::make('exception_reporting_enabled')->label('Exception reporting enabled'),
                ]),
            Section::make('Archival')
                ->schema([
                    Toggle::make('archival_enabled')->label('Archival enabled')->live(),
                    Select::make('archival_provider')
                        ->label('Provider')
                        ->native()
                        ->options([
                            'b2' => 'Backblaze B2',
                            's3' => 'Amazon S3',
                            'other' => 'Other S3-compatible',
                        ])
                        ->in(['b2', 's3', 'other'])
                        ->required($whenEnabled('archival_enabled'))
                        ->visible($whenEnabled('archival_enabled')),
                    $this->endpointField('archival_endpoint', 'Endpoint', 'archival_enabled'),
                    TextInput::make('archival_bucket')
                        ->label('Bucket')
                        ->maxLength(255)
                        ->required($whenEnabled('archival_enabled'))
                        ->visible($whenEnabled('archival_enabled')),
                ]),
            Section::make('Status page')
                ->schema([
                    Toggle::make('status_page_enabled')->label('Status page link enabled')->live(),
                    $this->endpointField('status_page_url', 'Status page URL', 'status_page_enabled'),
                ]),
            Section::make('Email')
                ->description('Credentials for the chosen mailer stay in .env. A long-lived queue:work needs a restart to pick up a change (queue:listen, as in this stack, does not).')
                ->schema([
                    Toggle::make('email_relay_enabled')
                        ->label('Email relay enabled')
                        ->helperText('Turning this off in production stops password resets, email verification and subscriber Email-channel alerts.')
                        ->live(),
                    Select::make('email_mailer')
                        ->label('Mailer')
                        ->native()
                        ->placeholder('Use the .env setting')
                        ->helperText('The chosen mailer\'s credentials must already be set in .env.')
                        ->options($mailers)
                        ->in(array_keys($mailers))
                        ->visible($whenEnabled('email_relay_enabled')),
                    TextInput::make('email_from_address')
                        ->label('From address')
                        ->email()
                        ->maxLength(255)
                        ->visible($whenEnabled('email_relay_enabled')),
                    TextInput::make('email_from_name')
                        ->label('From name')
                        ->maxLength(255)
                        ->visible($whenEnabled('email_relay_enabled')),
                ]),
        ]);
    }

    private function endpointField(string $name, string $label, string $toggle, bool $required = true, bool $testPing = false): TextInput
    {
        $field = TextInput::make($name)
            ->label($label)
            ->maxLength(255)
            ->rules(['url:http,https'])
            ->required($required ? fn (Get $get): bool => (bool) $get($toggle) : false)
            ->visible(fn (Get $get): bool => (bool) $get($toggle));

        return $testPing ? $field->suffixAction($this->testPingAction($name)) : $field;
    }

    /**
     * Sends a real GET to the URL currently typed in the field (saved or not) and
     * reports the status. Nothing about the URL is ever shown or logged: a
     * Healthchecks ping URL contains a secret UUID.
     */
    private function testPingAction(string $field): Action
    {
        return Action::make("testHeartbeat_{$field}")
            ->label('Send test ping')
            ->tooltip('Sends a real GET to this URL. Healthchecks counts it as a check-in.')
            ->icon(Heroicon::Signal)
            ->disabled(fn (Get $get): bool => blank($get($field)))
            ->action(function (Get $get) use ($field): void {
                $url = trim((string) $get($field));

                if (filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                    Notification::make()->danger()->title('Test ping failed')->body('Enter a full http:// or https:// URL first.')->send();

                    return;
                }

                $result = app(SendTestHeartbeatPing::class)($url);

                Notification::make()
                    ->title($result['ok'] ? 'Test ping sent' : 'Test ping failed')
                    ->body($result['message'])
                    ->color($result['ok'] ? 'success' : 'danger')
                    ->send();
            });
    }
}
