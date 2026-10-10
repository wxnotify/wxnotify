<?php

use App\Actions\Alerts\SaveAlertRule;
use App\Enums\Tier;
use App\Enums\UnitSystem;
use App\Filament\Dashboard\Resources\AlertRules\Pages\CreateAlertRule;
use App\Filament\Dashboard\Resources\AlertRules\Pages\EditAlertRule;
use App\Models\AlertRule;
use App\Models\AlertTemplate;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use App\Settings\TierThresholdSettings;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

/*
 * The dashboard's create page and the API write alert rules through the same SaveAlertRule action.
 * This runs the same scenario through both for equivalent subscribers and asserts the stored rows
 * (and relations) are identical, in both unit systems, so the two front ends cannot drift.
 */

beforeEach(function () {
    $this->seed(ProductCatalogSeeder::class);

    $limits = app(TierThresholdSettings::class);
    $limits->lightning_min_radius_miles = 10;
    $limits->save();

    $boundary = DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)");
    DB::table('cwa_boundaries')->insert(['wfo' => 'OKX', 'cwa' => 'OKX', 'citystate' => 'New York, NY', 'lon' => -72.5, 'lat' => 40.9, 'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('counties')->insert(['state' => 'NY', 'cwa' => 'OKX', 'countyname' => 'Suffolk', 'fips' => '36103', 'lon' => -72.5, 'lat' => 40.9, 'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('public_zones')->insert(['state' => 'NY', 'cwa' => 'OKX', 'zone' => '072', 'name' => 'Suffolk', 'state_zone' => 'NY072', 'lon' => -72.5, 'lat' => 40.9, 'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now()]);

    $this->tornado = Product::where('code', 'TO.W')->sole();
    $this->risk = Product::where('family', 'spc_wpc')->whereNotNull('risk_level_options')->orderBy('id')->firstOrFail();
});

/**
 * @return array{0: array<string, mixed>, 1: array<string, mixed>} the dashboard form state and the API body
 */
function parityPayloads($test, string $scenario, int $location, int $channel): array
{
    $tornado = $test->tornado;
    $risk = $test->risk;
    $levels = array_slice($risk->risk_level_options, 0, 2);
    $common = ['channel_ids' => [$channel]];

    return match ($scenario) {
        'threshold' => [$p = ['category' => 'threshold', 'variable' => 'wind_speed', 'direction' => 'above', 'threshold_value' => 40, 'timeframe' => 'day', 'location_id' => $location, 'delivery_time' => '06:30', 'name' => 'Windy', 'quiet_hours_start' => '22:00', 'quiet_hours_end' => '06:00', ...$common], $p],
        'daily summary' => [$p = ['category' => 'daily_summary', 'location_id' => $location, 'delivery_time' => '07:00', ...$common], $p],
        'lightning' => [
            ['category' => 'lightning', 'location_id' => $location, 'radius_miles' => 24, ...$common],
            ['category' => 'lightning', 'location_id' => $location, 'radius' => 24, ...$common],
        ],
        'weather nationwide' => [
            ['category' => 'weather_alerts', 'product_ids' => [$tornado->category->value => [$tornado->id]], 'matching_mode' => 'nationwide', ...$common],
            ['category' => 'weather_alerts', 'product_ids' => [$tornado->id], 'matching_mode' => 'nationwide', ...$common],
        ],
        'weather nationwide with stray locations' => [
            ['category' => 'weather_alerts', 'product_ids' => [$tornado->category->value => [$tornado->id]], 'matching_mode' => 'nationwide', 'location_ids' => [$location], ...$common],
            ['category' => 'weather_alerts', 'product_ids' => [$tornado->id], 'matching_mode' => 'nationwide', 'location_ids' => [$location], ...$common],
        ],
        'weather by location' => [
            ['category' => 'weather_alerts', 'product_ids' => [$tornado->category->value => [$tornado->id]], 'matching_mode' => 'location', 'location_ids' => [$location], ...$common],
            ['category' => 'weather_alerts', 'product_ids' => [$tornado->id], 'matching_mode' => 'location', 'location_ids' => [$location], ...$common],
        ],
        'weather by office' => [
            ['category' => 'weather_alerts', 'product_ids' => [$tornado->category->value => [$tornado->id]], 'matching_mode' => 'wfo', 'wfo' => 'OKX', ...$common],
            ['category' => 'weather_alerts', 'product_ids' => [$tornado->id], 'matching_mode' => 'wfo', 'wfo' => 'OKX', ...$common],
        ],
        'weather by county and zone' => [
            ['category' => 'weather_alerts', 'product_ids' => [$tornado->category->value => [$tornado->id]], 'matching_mode' => 'county_zone', 'county_zone_state' => 'NY', 'county_zone_codes' => ['NYC103', 'NYZ072'], ...$common],
            ['category' => 'weather_alerts', 'product_ids' => [$tornado->id], 'matching_mode' => 'county_zone', 'county_zone_codes' => ['NYC103', 'NYZ072'], ...$common],
        ],
        'spc by risk area' => [
            ['category' => 'spc_wpc', 'product_ids' => [$risk->category->value => [$risk->id]], 'matching_mode' => 'risk_area', 'location_ids' => [$location], 'risk_levels' => [$risk->category->value => $levels], ...$common],
            ['category' => 'spc_wpc', 'product_ids' => [$risk->id], 'matching_mode' => 'risk_area', 'location_ids' => [$location], 'risk_levels' => $levels, ...$common],
        ],
    };
}

/**
 * The stored rule without its identity, as comparable data.
 *
 * @return array<string, mixed>
 */
function storedShape(AlertRule $rule): array
{
    $rule->refresh();

    return [
        ...Arr::except($rule->getAttributes(), ['id', 'user_id', 'location_id', 'alert_template_id', 'created_at', 'updated_at']),
        'has_location' => $rule->location_id !== null,
        'has_template' => $rule->alert_template_id !== null,
        'products' => $rule->products->pluck('code')->sort()->values()->all(),
        'locations' => $rule->locations->count(),
        'channels' => $rule->notificationChannels->count(),
    ];
}

test('the dashboard and the API store the same rule', function (string $scenario, string $units) {
    $system = UnitSystem::from($units);

    $subscriber = function () use ($system): array {
        $user = User::factory()->create(['unit_system' => $system]);

        return [$user, Location::factory()->for($user)->create()->id, NotificationChannel::factory()->for($user)->create()->id];
    };

    [$formUser, $formLocation, $formChannel] = $subscriber();
    [$apiUser, $apiLocation, $apiChannel] = $subscriber();

    [$formState] = parityPayloads($this, $scenario, $formLocation, $formChannel);
    [, $apiBody] = parityPayloads($this, $scenario, $apiLocation, $apiChannel);

    $this->actingAs($formUser);
    livewire(CreateAlertRule::class)->fillForm($formState)->call('create')->assertHasNoFormErrors();
    $fromDashboard = AlertRule::where('user_id', $formUser->id)->sole();

    app('auth')->forgetGuards();
    $token = $apiUser->createToken('Pixel 9', ['app'])->plainTextToken;
    $this->withToken($token)->postJson('/api/v1/alert-rules', $apiBody)->assertCreated();
    $fromApi = AlertRule::where('user_id', $apiUser->id)->sole();

    expect(storedShape($fromApi))->toEqual(storedShape($fromDashboard));

    if (str_contains($scenario, 'stray locations')) {
        expect($fromApi->locations)->toBeEmpty()->and($fromDashboard->locations)->toBeEmpty();
    }
})->with([
    'threshold', 'daily summary', 'lightning', 'weather nationwide', 'weather nationwide with stray locations', 'weather by location',
    'weather by office', 'weather by county and zone', 'spc by risk area',
])->with(['imperial', 'metric']);

/**
 * A subscriber with a location, a channel and (for a paid tier) one template per template category.
 *
 * @return array{0: User, 1: int, 2: int, 3: array<string, int>}
 */
function paidSubscriber(UnitSystem $system): array
{
    $user = User::factory()->create(['unit_system' => $system, 'tier_override' => Tier::Plus]);
    $templates = [];

    foreach (['weather_alerts', 'spc_wpc'] as $category) {
        $templates[$category] = AlertTemplate::factory()->for($user)->create(['category' => $category])->id;
    }

    return [$user, Location::factory()->for($user)->create()->id, NotificationChannel::factory()->for($user)->create()->id, $templates];
}

test('the dashboard and the API store the same paid-tier message customisation', function (string $scenario, array $extras, ?string $templateCategory, string $units) {
    $system = UnitSystem::from($units);

    [$formUser, $formLocation, $formChannel, $formTemplates] = paidSubscriber($system);
    [$apiUser, $apiLocation, $apiChannel, $apiTemplates] = paidSubscriber($system);

    [$formState] = parityPayloads($this, $scenario, $formLocation, $formChannel);
    [, $apiBody] = parityPayloads($this, $scenario, $apiLocation, $apiChannel);

    $formState = [...$formState, ...$extras, ...($templateCategory ? ['alert_template_id' => $formTemplates[$templateCategory]] : [])];
    $apiBody = [...$apiBody, ...$extras, ...($templateCategory ? ['alert_template_id' => $apiTemplates[$templateCategory]] : [])];

    $this->actingAs($formUser);
    livewire(CreateAlertRule::class)->fillForm($formState)->call('create')->assertHasNoFormErrors();
    $fromDashboard = AlertRule::where('user_id', $formUser->id)->sole();

    app('auth')->forgetGuards();
    $token = $apiUser->createToken('Pixel 9', ['app'])->plainTextToken;
    $this->withToken($token)->postJson('/api/v1/alert-rules', $apiBody)->assertCreated();
    $fromApi = AlertRule::where('user_id', $apiUser->id)->sole();

    expect(storedShape($fromApi))->toEqual(storedShape($fromDashboard))
        ->and($fromApi->alert_template_id !== null)->toBe($templateCategory !== null)
        ->and($fromApi->message_body_style?->value)->toBe($extras['message_body_style'] ?? 'full');
})->with([
    'weather with length, subject and template' => ['weather nationwide', ['message_body_style' => 'brief', 'custom_subject_template' => '{product} at {location}'], 'weather_alerts'],
    'weather with the default length' => ['weather by location', [], null],
    'spc with a subject and template' => ['spc by risk area', ['custom_subject_template' => '{category}'], 'spc_wpc'],
    'threshold with length and subject' => ['threshold', ['message_body_style' => 'brief', 'custom_subject_template' => '{location}'], null],
    'daily summary with a subject' => ['daily summary', ['custom_subject_template' => '{location}'], null],
])->with(['imperial', 'metric']);

/**
 * Both subscribers start from the same rule, written through the action.
 *
 * @param  array<string, int>  $templates
 * @return array<string, mixed>
 */
function editStartData(string $start, int $location, int $channel, array $templates, $test): array
{
    $tornado = $test->tornado;
    $common = ['channel_ids' => [$channel], 'name' => 'Before', 'message_body_style' => 'brief', 'custom_subject_template' => '{product}'];

    return match ($start) {
        'weather by location' => ['category' => 'weather_alerts', 'product_ids' => [$tornado->id], 'matching_mode' => 'location', 'location_ids' => [$location], 'alert_template_id' => $templates['weather_alerts'], ...$common],
        'threshold' => ['category' => 'threshold', 'variable' => 'wind_speed', 'direction' => 'above', 'threshold_value' => 40, 'timeframe' => 'day', 'location_id' => $location, 'delivery_time' => '06:30', ...$common],
    };
}

/**
 * @return array{0: array<string, mixed>, 1: array<string, mixed>} the Livewire `data.*` changes and the full PUT body
 */
function editPayloads(string $scenario, int $location, int $channel, array $templates, $test): array
{
    $tornado = $test->tornado;
    $common = ['channel_ids' => [$channel], 'name' => 'Before', 'message_body_style' => 'brief', 'custom_subject_template' => '{product}'];

    return match ($scenario) {
        'weather to daily summary' => [
            ['category' => 'daily_summary', 'location_id' => $location, 'delivery_time' => '07:00'],
            ['category' => 'daily_summary', 'location_id' => $location, 'delivery_time' => '07:00', ...$common],
        ],
        'weather to threshold' => [
            ['category' => 'threshold', 'variable' => 'temperature', 'direction' => 'below', 'threshold_value' => 32, 'timeframe' => 'overnight', 'location_id' => $location, 'delivery_time' => '06:00'],
            ['category' => 'threshold', 'variable' => 'temperature', 'direction' => 'below', 'threshold_value' => 32, 'timeframe' => 'overnight', 'location_id' => $location, 'delivery_time' => '06:00', ...$common],
        ],
        'weather location to nationwide' => [
            ['matching_mode' => 'nationwide'],
            ['category' => 'weather_alerts', 'product_ids' => [$tornado->id], 'matching_mode' => 'nationwide', 'location_ids' => [$location], 'alert_template_id' => $templates['weather_alerts'], ...$common],
        ],
        'threshold to weather alerts' => [
            ['category' => 'weather_alerts', 'product_ids' => [$tornado->category->value => [$tornado->id]], 'matching_mode' => 'nationwide'],
            ['category' => 'weather_alerts', 'product_ids' => [$tornado->id], 'matching_mode' => 'nationwide', ...$common],
        ],
    };
}

test('the dashboard and the API store the same rule when an existing rule changes category', function (string $scenario, string $start, string $units) {
    $system = UnitSystem::from($units);

    [$formUser, $formLocation, $formChannel, $formTemplates] = paidSubscriber($system);
    [$apiUser, $apiLocation, $apiChannel, $apiTemplates] = paidSubscriber($system);

    $formRule = app(SaveAlertRule::class)($formUser, editStartData($start, $formLocation, $formChannel, $formTemplates, $this));
    $apiRule = app(SaveAlertRule::class)($apiUser, editStartData($start, $apiLocation, $apiChannel, $apiTemplates, $this));

    expect(storedShape($formRule))->toEqual(storedShape($apiRule));

    [$formChanges] = editPayloads($scenario, $formLocation, $formChannel, $formTemplates, $this);
    [, $apiBody] = editPayloads($scenario, $apiLocation, $apiChannel, $apiTemplates, $this);

    $this->actingAs($formUser);
    $page = livewire(EditAlertRule::class, ['record' => $formRule->id]);

    foreach ($formChanges as $key => $value) {
        $page->set("data.{$key}", $value);
    }

    $page->call('save')->assertHasNoFormErrors();

    app('auth')->forgetGuards();
    $token = $apiUser->createToken('Pixel 9', ['app'])->plainTextToken;
    $this->withToken($token)->putJson("/api/v1/alert-rules/{$apiRule->id}", $apiBody)->assertOk();

    expect(storedShape($apiRule))->toEqual(storedShape($formRule));
})->with([
    'weather to daily summary' => ['weather to daily summary', 'weather by location'],
    'weather to threshold' => ['weather to threshold', 'weather by location'],
    'weather location to nationwide' => ['weather location to nationwide', 'weather by location'],
    'threshold to weather alerts' => ['threshold to weather alerts', 'threshold'],
])->with(['imperial', 'metric']);
