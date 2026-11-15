<?php

use App\Actions\Alerts\SaveAlertRule;
use App\Enums\AlertCategory;
use App\Enums\Tier;
use App\Enums\UnitSystem;
use App\Models\AlertRule;
use App\Models\AlertTemplate;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\TierThresholdSettings;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(ProductCatalogSeeder::class);

    $limits = app(TierThresholdSettings::class);
    $limits->lightning_min_radius_miles = 10;
    $limits->save();

    $boundary = DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)");
    DB::table('cwa_boundaries')->insert(['wfo' => 'OKX', 'cwa' => 'OKX', 'citystate' => 'New York, NY', 'lon' => -72.5, 'lat' => 40.9, 'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('counties')->insert(['state' => 'NY', 'cwa' => 'OKX', 'countyname' => 'Suffolk', 'fips' => '36103', 'lon' => -72.5, 'lat' => 40.9, 'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('public_zones')->insert(['state' => 'NY', 'cwa' => 'OKX', 'zone' => '072', 'name' => 'Suffolk', 'state_zone' => 'NY072', 'lon' => -72.5, 'lat' => 40.9, 'boundary' => $boundary, 'created_at' => now(), 'updated_at' => now()]);

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('Pixel 9', ['app'])->plainTextToken;
    $this->location = Location::factory()->for($this->user)->create();
    $this->channel = NotificationChannel::factory()->for($this->user)->create();
    $this->tornado = Product::where('code', 'TO.W')->sole();
    $this->riskProduct = Product::where('family', 'spc_wpc')->whereNotNull('risk_level_options')->orderBy('id')->firstOrFail();
});

function ruleCall($test, string $method, string $uri, array $body = [], ?string $token = null)
{
    app('auth')->forgetGuards();

    return $test->withToken($token ?? $test->token)->json($method, $uri, $body);
}

/**
 * A valid request body for each kind of rule, built from the test's own records.
 */
function validRule($test, string $kind): array
{
    $common = ['channel_ids' => [$test->channel->id]];

    return match ($kind) {
        'threshold' => ['category' => 'threshold', 'variable' => 'temperature', 'direction' => 'below', 'threshold_value' => 32, 'timeframe' => 'overnight', 'location_id' => $test->location->id, 'delivery_time' => '06:00', ...$common],
        'daily summary' => ['category' => 'daily_summary', 'location_id' => $test->location->id, 'delivery_time' => '07:00', ...$common],
        'lightning' => ['category' => 'lightning', 'location_id' => $test->location->id, 'radius' => 15, ...$common],
        'weather nationwide' => ['category' => 'weather_alerts', 'product_ids' => [$test->tornado->id], 'matching_mode' => 'nationwide', ...$common],
        'weather by location' => ['category' => 'weather_alerts', 'product_ids' => [$test->tornado->id], 'matching_mode' => 'location', 'location_ids' => [$test->location->id], ...$common],
        'weather by office' => ['category' => 'weather_alerts', 'product_ids' => [$test->tornado->id], 'matching_mode' => 'wfo', 'wfo' => 'OKX', ...$common],
        'weather by county and zone' => ['category' => 'weather_alerts', 'product_ids' => [$test->tornado->id], 'matching_mode' => 'county_zone', 'county_zone_codes' => ['NYC103', 'NYZ072'], ...$common],
        'spc by risk area' => ['category' => 'spc_wpc', 'product_ids' => [$test->riskProduct->id], 'matching_mode' => 'risk_area', 'location_ids' => [$test->location->id], 'risk_levels' => array_slice($test->riskProduct->risk_level_options, 0, 2), ...$common],
    };
}

test('the rule endpoints need an app token', function () {
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/alert-rules')->assertUnauthorized();

    $beacon = $this->user->createToken('beacon', ['location'])->plainTextToken;
    ruleCall($this, 'GET', '/api/v1/alert-rules', [], $beacon)->assertForbidden();
});

test('the list is the subscriber\'s own rules, newest first, with every field the editor needs', function () {
    $older = AlertRule::factory()->for($this->user)->create(['location_id' => $this->location->id, 'name' => 'Freeze', 'quiet_hours_start' => '22:00', 'quiet_hours_end' => '06:00']);
    $newer = AlertRule::factory()->for($this->user)->lightning()->create(['location_id' => $this->location->id]);
    $older->notificationChannels()->attach($this->channel->id);
    AlertRule::factory()->create();

    $response = ruleCall($this, 'GET', '/api/v1/alert-rules')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$newer->id, $older->id]);

    $freeze = collect($response->json('data'))->firstWhere('id', $older->id);

    expect($freeze)->toMatchArray([
        'name' => 'Freeze', 'display_name' => 'Freeze', 'category' => 'threshold', 'category_label' => 'Threshold Alerts',
        'is_active' => true, 'variable' => 'temperature', 'direction' => 'below', 'threshold_value' => 32.0, 'threshold_unit' => '°F',
        'timeframe' => 'overnight', 'delivery_time' => '06:00', 'quiet_hours_start' => '22:00', 'quiet_hours_end' => '06:00',
        'location_id' => $this->location->id, 'channel_ids' => [$this->channel->id], 'product_ids' => [], 'county_zone_codes' => [],
    ]);
});

test('a Metric subscriber sees their own units', function () {
    $metric = User::factory()->create(['unit_system' => UnitSystem::Metric]);
    $location = Location::factory()->for($metric)->create();
    $threshold = AlertRule::factory()->for($metric)->create(['location_id' => $location->id, 'threshold_value' => 32]);
    $lightning = AlertRule::factory()->for($metric)->lightning()->create(['location_id' => $location->id, 'radius_miles' => 15]);

    $response = ruleCall($this, 'GET', '/api/v1/alert-rules', [], $metric->createToken('p', ['app'])->plainTextToken)->assertOk();

    $byId = collect($response->json('data'))->keyBy('id');

    expect($byId[$threshold->id])->toMatchArray(['threshold_value' => 0.0, 'threshold_unit' => '°C'])
        ->and($byId[$lightning->id])->toMatchArray(['radius' => 24, 'radius_unit' => 'km']);
});

test('showing a rule returns its products, locations and codes; another subscriber\'s rule is a 404', function () {
    $rule = AlertRule::factory()->for($this->user)->create(['category' => AlertCategory::WeatherAlerts, 'matching_mode' => 'county_zone', 'county_zone_codes' => 'NYC103,NYZ072', 'location_id' => null]);
    $rule->products()->attach($this->tornado->id);
    $rule->locations()->attach($this->location->id);
    $theirs = AlertRule::factory()->create();

    ruleCall($this, 'GET', "/api/v1/alert-rules/{$rule->id}")->assertOk()
        ->assertJsonPath('data.product_ids', [$this->tornado->id])
        ->assertJsonPath('data.location_ids', [$this->location->id])
        ->assertJsonPath('data.county_zone_codes', ['NYC103', 'NYZ072']);

    ruleCall($this, 'GET', "/api/v1/alert-rules/{$theirs->id}")->assertNotFound();
});

test('a rule saved per product reads the settings its products share as the flat fields', function () {
    $severe = Product::where('code', 'SV.W')->sole();
    $body = [...validRule($this, 'weather by location'), 'product_ids' => [$this->tornado->id, $severe->id]];

    ruleCall($this, 'POST', '/api/v1/alert-rules', $body)->assertCreated()
        ->assertJsonPath('data.matching_mode', 'location')
        ->assertJsonPath('data.location_ids', [$this->location->id])
        ->assertJsonPath('data.wfo', null)
        ->assertJsonPath('data.county_zone_codes', []);

    ruleCall($this, 'POST', '/api/v1/alert-rules', validRule($this, 'weather by office'))->assertCreated()
        ->assertJsonPath('data.matching_mode', 'wfo')
        ->assertJsonPath('data.wfo', 'OKX')
        ->assertJsonPath('data.location_ids', []);
});

test('a rule whose products match differently reads as mixed', function () {
    $severe = Product::where('code', 'SV.W')->sole();
    $rule = app(SaveAlertRule::class)($this->user, [
        'category' => 'weather_alerts',
        'product_settings' => [
            ['product_id' => $this->tornado->id, 'matching_mode' => 'polygon', 'location_ids' => [$this->location->id]],
            ['product_id' => $severe->id, 'matching_mode' => 'wfo', 'wfo' => 'OKX'],
        ],
        'channel_ids' => [$this->channel->id],
    ]);

    ruleCall($this, 'GET', "/api/v1/alert-rules/{$rule->id}")->assertOk()
        ->assertJsonPath('data.matching_mode', 'mixed')
        ->assertJsonPath('data.location_ids', [])
        ->assertJsonPath('data.wfo', null)
        ->assertJsonPath('data.county_zone_codes', []);
});

test('a valid rule of every kind is created with its relations', function (string $kind) {
    $response = ruleCall($this, 'POST', '/api/v1/alert-rules', validRule($this, $kind))->assertCreated();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($response->json('data.id'))->toBe($rule->id)
        ->and($rule->notificationChannels->pluck('id')->all())->toBe([$this->channel->id])
        ->and($rule->category->value)->toBe(validRule($this, $kind)['category'])
        ->and($rule->is_active)->toBeTrue();
})->with(['threshold', 'daily summary', 'lightning', 'weather nationwide', 'weather by location', 'weather by office', 'weather by county and zone', 'spc by risk area']);

test('the stored values match what was sent, in imperial units', function () {
    $metric = User::factory()->create(['unit_system' => UnitSystem::Metric]);
    $location = Location::factory()->for($metric)->create();
    $channel = NotificationChannel::factory()->for($metric)->create();
    $token = $metric->createToken('p', ['app'])->plainTextToken;

    ruleCall($this, 'POST', '/api/v1/alert-rules', ['category' => 'threshold', 'variable' => 'temperature', 'direction' => 'below', 'threshold_value' => 0, 'timeframe' => 'overnight', 'location_id' => $location->id, 'delivery_time' => '06:00', 'channel_ids' => [$channel->id]], $token)->assertCreated();
    ruleCall($this, 'POST', '/api/v1/alert-rules', ['category' => 'lightning', 'location_id' => $location->id, 'radius' => 24, 'channel_ids' => [$channel->id]], $token)->assertCreated();

    expect((float) AlertRule::where('category', 'threshold')->where('user_id', $metric->id)->sole()->threshold_value)->toBe(32.0)
        ->and(AlertRule::where('category', 'lightning')->where('user_id', $metric->id)->sole()->radius_miles)->toBe(15);
});

test('a refused request names the field and writes nothing', function (string $case, string $field) {
    $other = User::factory()->create();
    $body = match ($case) {
        'no category' => [...validRule($this, 'threshold'), 'category' => null],
        'no channels' => [...validRule($this, 'threshold'), 'channel_ids' => []],
        'a channel of another subscriber' => [...validRule($this, 'threshold'), 'channel_ids' => [NotificationChannel::factory()->for($other)->create()->id]],
        'a location of another subscriber' => [...validRule($this, 'threshold'), 'location_id' => Location::factory()->for($other)->create()->id],
        'a threshold without a value' => [...validRule($this, 'threshold'), 'threshold_value' => null],
        'a threshold value too large to store' => [...validRule($this, 'threshold'), 'threshold_value' => 1000000],
        'a threshold with an unknown variable' => [...validRule($this, 'threshold'), 'variable' => 'humidex'],
        'a delivery time that is not a clock time' => [...validRule($this, 'daily summary'), 'delivery_time' => '6am'],
        'a daily summary without a delivery time' => Arr::except(validRule($this, 'daily summary'), 'delivery_time'),
        'a lightning radius below the minimum' => [...validRule($this, 'lightning'), 'radius' => 9],
        'a lightning radius above 50 miles' => [...validRule($this, 'lightning'), 'radius' => 51],
        'a lightning radius that is not a whole number' => [...validRule($this, 'lightning'), 'radius' => 24.5],
        'weather alerts without products' => [...validRule($this, 'weather nationwide'), 'product_ids' => []],
        'a product from the wrong family' => [...validRule($this, 'weather nationwide'), 'product_ids' => [$this->riskProduct->id]],
        'risk area on a weather alert' => [...validRule($this, 'weather nationwide'), 'matching_mode' => 'risk_area'],
        'polygon on an SPC product that has risk levels' => [...validRule($this, 'spc by risk area'), 'matching_mode' => 'polygon'],
        'a location mode without locations' => [...validRule($this, 'weather by location'), 'location_ids' => []],
        'a location mode with another subscriber\'s location' => [...validRule($this, 'weather by location'), 'location_ids' => [Location::factory()->for($other)->create()->id]],
        'an unknown office' => [...validRule($this, 'weather by office'), 'wfo' => 'ZZZ'],
        'an unknown county code' => [...validRule($this, 'weather by county and zone'), 'county_zone_codes' => ['NYC999']],
        'a risk level the products do not offer' => [...validRule($this, 'spc by risk area'), 'risk_levels' => ['NOT-A-LEVEL']],
        'quiet hours that are not clock times' => [...validRule($this, 'threshold'), 'quiet_hours_start' => '10pm'],
    };

    ruleCall($this, 'POST', '/api/v1/alert-rules', $body)->assertUnprocessable()->assertJsonValidationErrorFor($field);

    expect(AlertRule::where('user_id', $this->user->id)->count())->toBe(0);
})->with([
    'no category' => ['no category', 'category'],
    'no channels' => ['no channels', 'channel_ids'],
    'a channel of another subscriber' => ['a channel of another subscriber', 'channel_ids.0'],
    'a location of another subscriber' => ['a location of another subscriber', 'location_id'],
    'a threshold without a value' => ['a threshold without a value', 'threshold_value'],
    'a threshold value too large to store' => ['a threshold value too large to store', 'threshold_value'],
    'a threshold with an unknown variable' => ['a threshold with an unknown variable', 'variable'],
    'a delivery time that is not a clock time' => ['a delivery time that is not a clock time', 'delivery_time'],
    'a daily summary without a delivery time' => ['a daily summary without a delivery time', 'delivery_time'],
    'a lightning radius below the minimum' => ['a lightning radius below the minimum', 'radius'],
    'a lightning radius above 50 miles' => ['a lightning radius above 50 miles', 'radius'],
    'a lightning radius that is not a whole number' => ['a lightning radius that is not a whole number', 'radius'],
    'weather alerts without products' => ['weather alerts without products', 'product_ids'],
    'a product from the wrong family' => ['a product from the wrong family', 'product_ids.0'],
    'risk area on a weather alert' => ['risk area on a weather alert', 'matching_mode'],
    'polygon on an SPC product that has risk levels' => ['polygon on an SPC product that has risk levels', 'matching_mode'],
    'a location mode without locations' => ['a location mode without locations', 'location_ids'],
    'a location mode with another subscriber\'s location' => ['a location mode with another subscriber\'s location', 'location_ids.0'],
    'an unknown office' => ['an unknown office', 'wfo'],
    'an unknown county code' => ['an unknown county code', 'county_zone_codes.0'],
    'a risk level the products do not offer' => ['a risk level the products do not offer', 'risk_levels.0'],
    'quiet hours that are not clock times' => ['quiet hours that are not clock times', 'quiet_hours_start'],
]);

test('fields that do not apply to the chosen matching mode are dropped, not stored', function () {
    $body = [...validRule($this, 'weather nationwide'), 'location_ids' => [$this->location->id], 'wfo' => 'OKX', 'county_zone_codes' => ['NYC103']];

    ruleCall($this, 'POST', '/api/v1/alert-rules', $body)->assertCreated();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    $settings = $rule->settingsFor($this->tornado->id);

    expect($settings->locations)->toBeEmpty()
        ->and($settings->wfo)->toBeNull()
        ->and($settings->countyZoneCodes)->toBe([]);
});

test('message customisation is stored for a paid subscriber and ignored for a Free one', function () {
    $body = [...validRule($this, 'weather nationwide'), 'message_body_style' => 'brief', 'custom_subject_template' => '{product}'];

    ruleCall($this, 'POST', '/api/v1/alert-rules', $body)->assertCreated();
    expect(AlertRule::where('user_id', $this->user->id)->sole())->message_body_style->toBeNull()->custom_subject_template->toBeNull();

    $this->user->forceFill(['tier_override' => Tier::Plus])->save();
    $template = AlertTemplate::factory()->for($this->user)->create(['category' => AlertCategory::WeatherAlerts]);
    $response = ruleCall($this, 'POST', '/api/v1/alert-rules', [...$body, 'alert_template_id' => $template->id])->assertCreated();

    $stored = AlertRule::find($response->json('data.id'));

    expect($stored->message_body_style->value)->toBe('brief')
        ->and($stored->custom_subject_template)->toBe('{product}')
        ->and($stored->alert_template_id)->toBe($template->id);

    $theirs = AlertTemplate::factory()->create(['category' => AlertCategory::WeatherAlerts]);
    ruleCall($this, 'POST', '/api/v1/alert-rules', [...$body, 'alert_template_id' => $theirs->id])->assertUnprocessable()->assertJsonValidationErrorFor('alert_template_id');
});

test('a rule can be replaced, including a change of category', function () {
    $rule = AlertRule::find(ruleCall($this, 'POST', '/api/v1/alert-rules', validRule($this, 'weather by location'))->json('data.id'));

    ruleCall($this, 'PUT', "/api/v1/alert-rules/{$rule->id}", validRule($this, 'daily summary'))->assertOk()->assertJsonPath('data.category', 'daily_summary');

    $rule->refresh();

    expect($rule->category)->toBe(AlertCategory::DailySummary)
        ->and($rule->matching_mode)->toBeNull()
        ->and($rule->products)->toBeEmpty()
        ->and($rule->locations)->toBeEmpty()
        ->and($rule->productLocations)->toBeEmpty()
        ->and($rule->location_id)->toBe($this->location->id);
});

test('another subscriber\'s rule cannot be replaced, switched or deleted', function () {
    $theirs = AlertRule::factory()->create(['name' => 'Theirs']);

    ruleCall($this, 'PUT', "/api/v1/alert-rules/{$theirs->id}", validRule($this, 'threshold'))->assertNotFound();
    ruleCall($this, 'PATCH', "/api/v1/alert-rules/{$theirs->id}", ['is_active' => false])->assertNotFound();
    ruleCall($this, 'DELETE', "/api/v1/alert-rules/{$theirs->id}")->assertNotFound();

    expect($theirs->fresh())->name->toBe('Theirs')->is_active->toBeTrue();
});

test('a rule is switched off and on with PATCH', function () {
    $rule = AlertRule::factory()->for($this->user)->create(['location_id' => $this->location->id]);

    ruleCall($this, 'PATCH', "/api/v1/alert-rules/{$rule->id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    expect($rule->fresh()->is_active)->toBeFalse();

    ruleCall($this, 'PATCH', "/api/v1/alert-rules/{$rule->id}", ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
    ruleCall($this, 'PATCH', "/api/v1/alert-rules/{$rule->id}", [])->assertUnprocessable()->assertJsonValidationErrorFor('is_active');
});

test('a rule is deleted with its relations', function () {
    $rule = AlertRule::find(ruleCall($this, 'POST', '/api/v1/alert-rules', validRule($this, 'weather by location'))->json('data.id'));

    ruleCall($this, 'DELETE', "/api/v1/alert-rules/{$rule->id}")->assertNoContent();

    expect(AlertRule::find($rule->id))->toBeNull()
        ->and(DB::table('alert_rule_location')->where('alert_rule_id', $rule->id)->exists())->toBeFalse();
});

test('every write is logged with the subscriber as the causer', function () {
    $created = ruleCall($this, 'POST', '/api/v1/alert-rules', validRule($this, 'threshold'))->json('data.id');
    ruleCall($this, 'PATCH', "/api/v1/alert-rules/{$created}", ['is_active' => false]);
    ruleCall($this, 'DELETE', "/api/v1/alert-rules/{$created}");

    $activities = Activity::query()->where('subject_type', AlertRule::class)->get();

    expect($activities)->not->toBeEmpty()
        ->and($activities->pluck('causer_id')->unique()->all())->toBe([$this->user->id]);
});

test('during maintenance a write answers 503 with the maintenance message as plain text and changes nothing', function () {
    $settings = app(GeneralSettings::class);
    $settings->maintenance_mode = true;
    $settings->maintenance_message = 'Back soon.';
    $settings->save();

    $response = ruleCall($this, 'POST', '/api/v1/alert-rules', validRule($this, 'threshold'));

    $response->assertStatus(503);
    expect($response->getContent())->toBe('Back soon.')
        ->and($response->headers->get('Content-Type'))->toStartWith('text/plain')
        ->and(AlertRule::where('user_id', $this->user->id)->count())->toBe(0);
});

test('a Metric subscriber replacing a threshold rule has the new value converted to storage', function () {
    $metric = User::factory()->create(['unit_system' => UnitSystem::Metric]);
    $location = Location::factory()->for($metric)->create();
    $channel = NotificationChannel::factory()->for($metric)->create();
    $token = $metric->createToken('p', ['app'])->plainTextToken;
    $body = ['category' => 'threshold', 'variable' => 'temperature', 'direction' => 'below', 'threshold_value' => 0, 'timeframe' => 'overnight', 'location_id' => $location->id, 'delivery_time' => '06:00', 'channel_ids' => [$channel->id]];

    $id = ruleCall($this, 'POST', '/api/v1/alert-rules', $body, $token)->assertCreated()->json('data.id');

    ruleCall($this, 'PUT', "/api/v1/alert-rules/{$id}", [...$body, 'threshold_value' => 10], $token)->assertOk()->assertJsonPath('data.threshold_value', 10);
    expect((float) AlertRule::find($id)->threshold_value)->toBe(50.0);

    $withoutVariable = Arr::except($body, 'variable');
    ruleCall($this, 'PUT', "/api/v1/alert-rules/{$id}", $withoutVariable, $token)->assertUnprocessable()->assertJsonValidationErrorFor('variable');
    expect((float) AlertRule::find($id)->threshold_value)->toBe(50.0);
});

/**
 * A valid per-product body: Tornado by my location, Severe Thunderstorm by office.
 *
 * @return array<string, mixed>
 */
function perProductRuleBody($test): array
{
    return [
        'category' => 'weather_alerts',
        'channel_ids' => [$test->channel->id],
        'product_settings' => [
            ['product_id' => $test->tornado->id, 'matching_mode' => 'location', 'location_ids' => [$test->location->id]],
            ['product_id' => Product::where('code', 'SV.W')->sole()->id, 'matching_mode' => 'wfo', 'wfo' => 'OKX'],
        ],
    ];
}

test('a rule is created with a mode of its own for each product and reads back as mixed with its product settings', function () {
    $severe = Product::where('code', 'SV.W')->sole();

    $response = ruleCall($this, 'POST', '/api/v1/alert-rules', perProductRuleBody($this))->assertCreated()
        ->assertJsonPath('data.matching_mode', 'mixed')
        ->assertJsonPath('data.location_ids', [])
        ->assertJsonPath('data.wfo', null)
        ->assertJsonPath('data.county_zone_codes', []);

    expect($response->json('data.product_ids'))->toBe([$this->tornado->id, $severe->id])
        ->and($response->json('data.product_settings'))->toBe([
            ['product_id' => $this->tornado->id, 'matching_mode' => 'location', 'location_ids' => [$this->location->id], 'wfo' => null, 'county_zone_codes' => [], 'custom_subject_template' => null],
            ['product_id' => $severe->id, 'matching_mode' => 'wfo', 'location_ids' => [], 'wfo' => 'OKX', 'county_zone_codes' => [], 'custom_subject_template' => null],
        ]);

    $rule = AlertRule::find($response->json('data.id'));

    expect($rule->settingsFor($this->tornado->id)->locations->pluck('id')->all())->toBe([$this->location->id])
        ->and($rule->settingsFor($severe->id)->wfo)->toBe('OKX')
        ->and($rule->matching_mode)->toBeNull();
});

test('a county/zone product setting is stored and read back as codes', function () {
    $body = ['category' => 'weather_alerts', 'channel_ids' => [$this->channel->id], 'product_settings' => [
        ['product_id' => $this->tornado->id, 'matching_mode' => 'county_zone', 'county_zone_codes' => ['NYC103', 'NYZ072']],
    ]];

    ruleCall($this, 'POST', '/api/v1/alert-rules', $body)->assertCreated()
        ->assertJsonPath('data.matching_mode', 'county_zone')
        ->assertJsonPath('data.county_zone_codes', ['NYC103', 'NYZ072'])
        ->assertJsonPath('data.product_settings.0.county_zone_codes', ['NYC103', 'NYZ072']);
});

test('a rule is replaced with new product settings and leaves no orphan rows', function () {
    $id = ruleCall($this, 'POST', '/api/v1/alert-rules', perProductRuleBody($this))->json('data.id');
    $watch = Product::where('family', 'watch')->orderBy('id')->firstOrFail();

    $body = ['category' => 'weather_alerts', 'channel_ids' => [$this->channel->id], 'product_settings' => [
        ['product_id' => $watch->id, 'matching_mode' => 'nationwide'],
    ]];

    ruleCall($this, 'PUT', "/api/v1/alert-rules/{$id}", $body)->assertOk()
        ->assertJsonPath('data.product_ids', [$watch->id])
        ->assertJsonPath('data.matching_mode', 'nationwide')
        ->assertJsonPath('data.product_settings', [['product_id' => $watch->id, 'matching_mode' => 'nationwide', 'location_ids' => [], 'wfo' => null, 'county_zone_codes' => [], 'custom_subject_template' => null]]);

    expect(DB::table('alert_rule_product_location')->where('alert_rule_id', $id)->count())->toBe(0)
        ->and(DB::table('alert_rule_product')->where('alert_rule_id', $id)->count())->toBe(1);
});

test('product settings and the flat fields in one request are refused', function (string $flatKey, mixed $value) {
    $body = [...perProductRuleBody($this), $flatKey => $value];

    ruleCall($this, 'POST', '/api/v1/alert-rules', $body)->assertUnprocessable()->assertJsonValidationErrorFor('product_settings');

    expect(AlertRule::where('user_id', $this->user->id)->count())->toBe(0);
})->with([
    'product_ids' => ['product_ids', [1]],
    'matching_mode' => ['matching_mode', 'nationwide'],
    'location_ids' => ['location_ids', [1]],
    'wfo' => ['wfo', 'OKX'],
    'county_zone_codes' => ['county_zone_codes', ['NYC103']],
]);

test('an invalid product setting is refused naming its field and writes nothing', function (string $case, string $field) {
    $other = User::factory()->create();
    $severe = Product::where('code', 'SV.W')->sole();
    $entry = fn (array $overrides, ?int $productId = null): array => ['category' => 'weather_alerts', 'channel_ids' => [$this->channel->id], 'product_settings' => [
        ['product_id' => $productId ?? $this->tornado->id, 'matching_mode' => 'nationwide', ...$overrides],
    ]];

    $body = match ($case) {
        'empty list' => [...perProductRuleBody($this), 'product_settings' => []],
        'risk area on a weather alert' => $entry(['matching_mode' => 'risk_area', 'location_ids' => [$this->location->id]]),
        'mixed echoed back' => $entry(['matching_mode' => 'mixed']),
        'unknown mode' => $entry(['matching_mode' => 'sideways']),
        'missing mode' => ['category' => 'weather_alerts', 'channel_ids' => [$this->channel->id], 'product_settings' => [['product_id' => $this->tornado->id]]],
        'location mode without locations' => $entry(['matching_mode' => 'location']),
        'polygon mode without locations' => $entry(['matching_mode' => 'polygon', 'location_ids' => []]),
        'another subscriber\'s location' => $entry(['matching_mode' => 'location', 'location_ids' => [Location::factory()->for($other)->create()->id]]),
        'office mode without an office' => $entry(['matching_mode' => 'wfo']),
        'unknown office' => $entry(['matching_mode' => 'wfo', 'wfo' => 'ZZZ']),
        'county mode without codes' => $entry(['matching_mode' => 'county_zone']),
        'unknown county code' => $entry(['matching_mode' => 'county_zone', 'county_zone_codes' => ['NYC999']]),
        'product of the wrong family' => $entry([], $this->riskProduct->id),
        'unknown product' => $entry([], 999999),
        'the same product twice' => ['category' => 'weather_alerts', 'channel_ids' => [$this->channel->id], 'product_settings' => [
            ['product_id' => $severe->id, 'matching_mode' => 'nationwide'],
            ['product_id' => $severe->id, 'matching_mode' => 'nationwide'],
        ]],
        'location mode on a later row without locations' => ['category' => 'weather_alerts', 'channel_ids' => [$this->channel->id], 'product_settings' => [
            ['product_id' => $this->tornado->id, 'matching_mode' => 'location', 'location_ids' => [$this->location->id]],
            ['product_id' => $severe->id, 'matching_mode' => 'location', 'location_ids' => []],
        ]],
    };

    ruleCall($this, 'POST', '/api/v1/alert-rules', $body)->assertUnprocessable()->assertJsonValidationErrorFor($field);

    expect(AlertRule::where('user_id', $this->user->id)->count())->toBe(0);
})->with([
    'empty list' => ['empty list', 'product_settings'],
    'risk area on a weather alert' => ['risk area on a weather alert', 'product_settings.0.matching_mode'],
    'mixed echoed back' => ['mixed echoed back', 'product_settings.0.matching_mode'],
    'unknown mode' => ['unknown mode', 'product_settings.0.matching_mode'],
    'missing mode' => ['missing mode', 'product_settings.0.matching_mode'],
    'location mode without locations' => ['location mode without locations', 'product_settings.0.location_ids'],
    'polygon mode without locations' => ['polygon mode without locations', 'product_settings.0.location_ids'],
    'another subscriber\'s location' => ['another subscriber\'s location', 'product_settings.0.location_ids.0'],
    'office mode without an office' => ['office mode without an office', 'product_settings.0.wfo'],
    'unknown office' => ['unknown office', 'product_settings.0.wfo'],
    'county mode without codes' => ['county mode without codes', 'product_settings.0.county_zone_codes'],
    'unknown county code' => ['unknown county code', 'product_settings.0.county_zone_codes.0'],
    'product of the wrong family' => ['product of the wrong family', 'product_settings.0.product_id'],
    'unknown product' => ['unknown product', 'product_settings.0.product_id'],
    'the same product twice' => ['the same product twice', 'product_settings.0.product_id'],
    'location mode on a later row without locations' => ['location mode on a later row without locations', 'product_settings.1.location_ids'],
]);

test('each SPC and WPC product setting accepts polygon and risk area, never location', function () {
    $plain = Product::where('family', 'spc_wpc')->whereNull('risk_level_options')->orderBy('id')->firstOrFail();
    $body = fn (string $mode, Product $product): array => ['category' => 'spc_wpc', 'channel_ids' => [$this->channel->id], 'product_settings' => [
        ['product_id' => $product->id, 'matching_mode' => $mode, 'location_ids' => [$this->location->id]],
    ]];

    ruleCall($this, 'POST', '/api/v1/alert-rules', $body('polygon', $plain))->assertCreated()->assertJsonPath('data.matching_mode', 'polygon');
    ruleCall($this, 'POST', '/api/v1/alert-rules', $body('risk_area', $this->riskProduct))->assertCreated()->assertJsonPath('data.matching_mode', 'risk_area');
    ruleCall($this, 'POST', '/api/v1/alert-rules', $body('location', $plain))->assertUnprocessable()->assertJsonValidationErrorFor('product_settings.0.matching_mode');
});

test('SPC and WPC risk levels are taken and read back per product, with the flat list as every product\'s levels together', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $rainfall = Product::where('code', 'WPC.ERO')->sole();
    $body = ['category' => 'spc_wpc', 'channel_ids' => [$this->channel->id], 'product_settings' => [
        ['product_id' => $outlook->id, 'matching_mode' => 'nationwide', 'risk_levels' => ['Enhanced']],
        ['product_id' => $rainfall->id, 'matching_mode' => 'nationwide', 'risk_levels' => ['Moderate']],
    ]];

    $response = ruleCall($this, 'POST', '/api/v1/alert-rules', $body)->assertCreated()
        ->assertJsonPath('data.matching_mode', 'nationwide')
        ->assertJsonPath('data.risk_levels', ['Enhanced', 'Moderate']);

    expect(collect($response->json('data.product_settings'))->pluck('risk_levels', 'product_id')->all())
        ->toBe([$outlook->id => ['Enhanced'], $rainfall->id => ['Moderate']]);
});

test('a flat-only save that repeats the stored levels keeps each product\'s own levels', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $rainfall = Product::where('code', 'WPC.ERO')->sole();
    $id = ruleCall($this, 'POST', '/api/v1/alert-rules', ['category' => 'spc_wpc', 'channel_ids' => [$this->channel->id], 'product_settings' => [
        ['product_id' => $outlook->id, 'matching_mode' => 'nationwide', 'risk_levels' => ['Moderate']],
        ['product_id' => $rainfall->id, 'matching_mode' => 'nationwide', 'risk_levels' => []],
    ]])->assertCreated()->json('data.id');

    ruleCall($this, 'PUT', "/api/v1/alert-rules/{$id}", [
        'category' => 'spc_wpc', 'name' => 'Renamed', 'channel_ids' => [$this->channel->id],
        'product_ids' => [$outlook->id, $rainfall->id], 'matching_mode' => 'nationwide', 'risk_levels' => ['Moderate'],
    ])->assertOk()->assertJsonPath('data.name', 'Renamed');

    $rule = AlertRule::find($id);
    expect($rule->riskLevelsFor($outlook->id))->toBe(['Moderate'])
        ->and($rule->riskLevelsFor($rainfall->id))->toBe([])
        ->and($rule->risk_levels)->toBe(['Moderate']);
});

test('a flat-only save with different levels applies them to every product', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $rainfall = Product::where('code', 'WPC.ERO')->sole();
    $id = ruleCall($this, 'POST', '/api/v1/alert-rules', ['category' => 'spc_wpc', 'channel_ids' => [$this->channel->id], 'product_settings' => [
        ['product_id' => $outlook->id, 'matching_mode' => 'nationwide', 'risk_levels' => ['Moderate']],
        ['product_id' => $rainfall->id, 'matching_mode' => 'nationwide', 'risk_levels' => []],
    ]])->assertCreated()->json('data.id');

    ruleCall($this, 'PUT', "/api/v1/alert-rules/{$id}", [
        'category' => 'spc_wpc', 'channel_ids' => [$this->channel->id],
        'product_ids' => [$outlook->id, $rainfall->id], 'matching_mode' => 'nationwide', 'risk_levels' => ['High'],
    ])->assertOk();

    $rule = AlertRule::find($id);
    expect($rule->riskLevelsFor($outlook->id))->toBe(['High'])
        ->and($rule->riskLevelsFor($rainfall->id))->toBe(['High']);
});

test('a product setting\'s risk level that the product itself does not offer is refused', function () {
    $rainfall = Product::where('code', 'WPC.ERO')->sole();
    $body = ['category' => 'spc_wpc', 'channel_ids' => [$this->channel->id], 'product_settings' => [
        ['product_id' => $rainfall->id, 'matching_mode' => 'nationwide', 'risk_levels' => ['Enhanced']],
    ]];

    ruleCall($this, 'POST', '/api/v1/alert-rules', $body)->assertUnprocessable()->assertJsonValidationErrorFor('product_settings.0.risk_levels.0');

    expect(AlertRule::count())->toBe(0);
});

test('a product setting\'s risk level that no product offers is refused', function () {
    $body = ['category' => 'spc_wpc', 'channel_ids' => [$this->channel->id], 'product_settings' => [
        ['product_id' => $this->riskProduct->id, 'matching_mode' => 'nationwide', 'risk_levels' => ['NOT-A-LEVEL']],
    ]];

    ruleCall($this, 'POST', '/api/v1/alert-rules', $body)->assertUnprocessable()->assertJsonValidationErrorFor('product_settings.0.risk_levels.0');

    expect(AlertRule::count())->toBe(0);
});

test('a PUT with no product or matching fields keeps a mixed rule exactly as stored', function () {
    $id = ruleCall($this, 'POST', '/api/v1/alert-rules', perProductRuleBody($this))->json('data.id');
    $before = ruleCall($this, 'GET', "/api/v1/alert-rules/{$id}")->json('data');

    $other = NotificationChannel::factory()->for($this->user)->create();

    ruleCall($this, 'PUT', "/api/v1/alert-rules/{$id}", ['category' => 'weather_alerts', 'name' => 'Renamed', 'channel_ids' => [$other->id]])->assertOk();

    $after = ruleCall($this, 'GET', "/api/v1/alert-rules/{$id}")->json('data');

    expect($after)->toMatchArray([...$before, 'name' => 'Renamed', 'channel_ids' => [$other->id], 'display_name' => 'Renamed'])
        ->and($after['product_settings'])->toBe($before['product_settings'])
        ->and($after['matching_mode'])->toBe('mixed');
});

test('a PUT with no product or matching fields is refused when it changes the category', function (string $category) {
    $id = ruleCall($this, 'POST', '/api/v1/alert-rules', perProductRuleBody($this))->json('data.id');

    ruleCall($this, 'PUT', "/api/v1/alert-rules/{$id}", ['category' => $category, 'channel_ids' => [$this->channel->id]])
        ->assertUnprocessable()->assertJsonValidationErrorFor('product_ids');

    expect(AlertRule::find($id)->category)->toBe(AlertCategory::WeatherAlerts)
        ->and(AlertRule::find($id)->products)->toHaveCount(2);
})->with(['spc_wpc']);

test('a POST with neither product settings nor product ids is refused', function () {
    ruleCall($this, 'POST', '/api/v1/alert-rules', ['category' => 'weather_alerts', 'channel_ids' => [$this->channel->id]])
        ->assertUnprocessable()->assertJsonValidationErrorFor('product_ids');
});

test('a PUT with a stray matching field but no products is not the keep path', function () {
    $id = ruleCall($this, 'POST', '/api/v1/alert-rules', perProductRuleBody($this))->json('data.id');

    ruleCall($this, 'PUT', "/api/v1/alert-rules/{$id}", ['category' => 'weather_alerts', 'channel_ids' => [$this->channel->id], 'matching_mode' => 'nationwide'])
        ->assertUnprocessable()->assertJsonValidationErrorFor('product_ids');
});

test('reading rules does not query per product', function () {
    // Sanctum writes the token's last_used_at only when the clock second changed, so an unfrozen
    // clock adds a query to whichever read crosses a second boundary.
    $this->freezeTime();

    ruleCall($this, 'POST', '/api/v1/alert-rules', perProductRuleBody($this))->assertCreated();
    ruleCall($this, 'POST', '/api/v1/alert-rules', perProductRuleBody($this))->assertCreated();

    DB::enableQueryLog();
    ruleCall($this, 'GET', '/api/v1/alert-rules')->assertOk();
    $withTwo = count(DB::getQueryLog());
    DB::flushQueryLog();

    ruleCall($this, 'POST', '/api/v1/alert-rules', perProductRuleBody($this))->assertCreated();
    DB::flushQueryLog();
    ruleCall($this, 'GET', '/api/v1/alert-rules')->assertOk();
    $withThree = count(DB::getQueryLog());

    expect($withThree)->toBe($withTwo);
});

/**
 * A per-product body with a subject on each of two products, created by a paid subscriber.
 *
 * @return array{0: int, 1: Product}
 */
function apiSubjectsRule($test, array $subjects = ['{product} here', 'Severe: {location}']): array
{
    $test->user->forceFill(['tier_override' => Tier::Plus])->save();
    $severe = Product::where('code', 'SV.W')->sole();
    $body = perProductRuleBody($test);
    $body['product_settings'][0]['custom_subject_template'] = $subjects[0];
    $body['product_settings'][1]['custom_subject_template'] = $subjects[1];

    return [ruleCall($test, 'POST', '/api/v1/alert-rules', $body)->assertCreated()->json('data.id'), $severe];
}

test('a paid subscriber stores a subject per product and the resource returns it on each entry', function () {
    [$id, $severe] = apiSubjectsRule($this);

    expect(DB::table('alert_rule_product')->where('alert_rule_id', $id)->pluck('custom_subject_template', 'product_id')->all())
        ->toBe([$this->tornado->id => '{product} here', $severe->id => 'Severe: {location}']);

    ruleCall($this, 'GET', "/api/v1/alert-rules/{$id}")->assertOk()
        ->assertJsonPath('data.product_settings.0.custom_subject_template', '{product} here')
        ->assertJsonPath('data.product_settings.1.custom_subject_template', 'Severe: {location}')
        ->assertJsonPath('data.custom_subject_template', null);
});

test('an update whose entries omit the subject key keeps every stored product subject', function () {
    [$id, $severe] = apiSubjectsRule($this);

    ruleCall($this, 'PUT', "/api/v1/alert-rules/{$id}", perProductRuleBody($this))->assertOk()
        ->assertJsonPath('data.product_settings.0.custom_subject_template', '{product} here')
        ->assertJsonPath('data.product_settings.1.custom_subject_template', 'Severe: {location}');
});

test('a flat product_ids update keeps the subjects of the products that stay', function () {
    [$id, $severe] = apiSubjectsRule($this);

    $body = ['category' => 'weather_alerts', 'channel_ids' => [$this->channel->id], 'product_ids' => [$this->tornado->id, $severe->id], 'matching_mode' => 'nationwide'];

    ruleCall($this, 'PUT', "/api/v1/alert-rules/{$id}", $body)->assertOk();

    expect(DB::table('alert_rule_product')->where('alert_rule_id', $id)->pluck('custom_subject_template', 'product_id')->all())
        ->toBe([$this->tornado->id => '{product} here', $severe->id => 'Severe: {location}']);
});

test('an explicit null or empty subject clears just that product', function (mixed $cleared) {
    [$id, $severe] = apiSubjectsRule($this);

    $body = perProductRuleBody($this);
    $body['product_settings'][0]['custom_subject_template'] = $cleared;

    ruleCall($this, 'PUT', "/api/v1/alert-rules/{$id}", $body)->assertOk()
        ->assertJsonPath('data.product_settings.0.custom_subject_template', null)
        ->assertJsonPath('data.product_settings.1.custom_subject_template', 'Severe: {location}');
})->with([null, '', '   ']);

test('a per-product subject is trimmed and refused past 255 characters or when not a string', function () {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();

    $body = perProductRuleBody($this);
    $body['product_settings'][0]['custom_subject_template'] = '  {product}  ';
    $id = ruleCall($this, 'POST', '/api/v1/alert-rules', $body)->assertCreated()
        ->assertJsonPath('data.product_settings.0.custom_subject_template', '{product}')->json('data.id');

    foreach ([str_repeat('a', 256), ['x'], 12] as $bad) {
        $body['product_settings'][0]['custom_subject_template'] = $bad;
        ruleCall($this, 'PUT', "/api/v1/alert-rules/{$id}", $body)->assertUnprocessable()
            ->assertJsonValidationErrorFor('product_settings.0.custom_subject_template');
    }

    expect(DB::table('alert_rule_product')->where('alert_rule_id', $id)->where('product_id', $this->tornado->id)->value('custom_subject_template'))->toBe('{product}');
});

test('a Free subscriber\'s per-product subject is ignored without an error', function () {
    $body = perProductRuleBody($this);
    $body['product_settings'][0]['custom_subject_template'] = str_repeat('a', 300);

    $id = ruleCall($this, 'POST', '/api/v1/alert-rules', $body)->assertCreated()
        ->assertJsonPath('data.product_settings.0.custom_subject_template', null)->json('data.id');

    expect(DB::table('alert_rule_product')->where('alert_rule_id', $id)->whereNotNull('custom_subject_template')->count())->toBe(0);
});

test('a downgraded owner reads a null subject but a save keeps the stored product subject', function () {
    [$id, $severe] = apiSubjectsRule($this);
    $this->user->forceFill(['tier_override' => Tier::Free])->save();
    $stored = fn (): array => DB::table('alert_rule_product')->where('alert_rule_id', $id)->pluck('custom_subject_template', 'product_id')->all();
    $expected = [$this->tornado->id => '{product} here', $severe->id => 'Severe: {location}'];

    ruleCall($this, 'GET', "/api/v1/alert-rules/{$id}")->assertOk()
        ->assertJsonPath('data.product_settings.0.custom_subject_template', null)
        ->assertJsonPath('data.product_settings.1.custom_subject_template', null);

    ruleCall($this, 'PUT', "/api/v1/alert-rules/{$id}", perProductRuleBody($this))->assertOk();
    expect($stored())->toBe($expected);

    $flat = ['category' => 'weather_alerts', 'channel_ids' => [$this->channel->id], 'product_ids' => [$this->tornado->id, $severe->id], 'matching_mode' => 'nationwide'];
    ruleCall($this, 'PUT', "/api/v1/alert-rules/{$id}", $flat)->assertOk();
    expect($stored())->toBe($expected);
});
