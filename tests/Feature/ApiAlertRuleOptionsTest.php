<?php

use App\Enums\AlertCategory;
use App\Enums\Tier;
use App\Enums\UnitSystem;
use App\Models\AlertRule;
use App\Models\AlertTemplate;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use App\Settings\TierThresholdSettings;
use App\Support\PushSounds;
use Database\Seeders\ProductCatalogSeeder;

beforeEach(function () {
    $this->seed(ProductCatalogSeeder::class);

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('Pixel 9', ['app'])->plainTextToken;
});

function optionsCall($test, ?string $token = null)
{
    app('auth')->forgetGuards();

    return $test->withToken($token ?? $test->token)->getJson('/api/v1/alert-rule-options');
}

test('it needs an app token', function () {
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/alert-rule-options')->assertUnauthorized();

    $beacon = $this->user->createToken('beacon', ['location'])->plainTextToken;
    optionsCall($this, $beacon)->assertForbidden();
});

test('it lists the categories, the matching modes and the active products grouped by product category', function () {
    $retired = Product::factory()->retired()->create(['family' => 'warning', 'category' => Product::where('code', 'TO.W')->sole()->category]);

    $response = optionsCall($this)->assertOk();

    expect(collect($response->json('data.categories'))->pluck('value')->all())->toBe(collect(AlertCategory::cases())->reject(fn (AlertCategory $c) => $c === AlertCategory::OutlookReport)->map(fn (AlertCategory $c) => $c->value)->values()->all())
        ->and(collect($response->json('data.matching_modes'))->pluck('value')->all())->toContain('nationwide', 'location', 'wfo', 'county_zone', 'polygon', 'risk_area');

    $tornado = Product::where('code', 'TO.W')->sole();
    $weatherIds = collect($response->json('data.products.weather_alerts'))->pluck('products')->flatten(1)->pluck('id');
    $group = collect($response->json('data.products.weather_alerts'))->firstWhere('category', $tornado->category->value);

    expect($weatherIds)->toContain($tornado->id)->not->toContain($retired->id)
        ->and($group['label'])->toBe($tornado->category->label());
});

test('SPC and WPC products carry their risk levels', function () {
    $risk = Product::where('family', 'spc_wpc')->whereNotNull('risk_level_options')->orderBy('id')->firstOrFail();

    $response = optionsCall($this)->assertOk();
    $product = collect($response->json('data.products.spc_wpc'))->pluck('products')->flatten(1)->firstWhere('id', $risk->id);

    expect($product['risk_levels'])->toBe($risk->risk_level_options);
});

test('threshold units and the lightning radius bounds follow the subscriber\'s unit system', function () {
    $limits = app(TierThresholdSettings::class);
    $limits->lightning_min_radius_miles = 10;
    $limits->save();

    $imperial = optionsCall($this)->assertOk();
    $metricUser = User::factory()->create(['unit_system' => UnitSystem::Metric]);
    $metric = optionsCall($this, $metricUser->createToken('p', ['app'])->plainTextToken)->assertOk();

    $wind = fn ($response) => collect($response->json('data.thresholds.variables'))->firstWhere('value', 'wind_speed');

    expect($imperial->json('data.units.system'))->toBe('imperial')
        ->and($wind($imperial)['unit'])->toBe('mph')
        ->and($imperial->json('data.lightning'))->toMatchArray(['unit' => 'mi', 'min' => 10, 'max' => 50, 'default' => 15])
        ->and($metric->json('data.units.system'))->toBe('metric')
        ->and($wind($metric))->toMatchArray(['unit' => 'km/h', 'decimals' => 0])
        ->and($metric->json('data.lightning'))->toMatchArray(['unit' => 'km', 'min' => 17, 'max' => 80, 'default' => 24]);
});

test('message customisation is offered to paid tiers only', function () {
    expect(optionsCall($this)->json('data.tier.can_customize_messages'))->toBeFalse();

    $this->user->forceFill(['tier_override' => Tier::Plus])->save();

    expect(optionsCall($this)->json('data.tier.can_customize_messages'))->toBeTrue();
});

test('channels, templates and locations are the subscriber\'s own and never expose a destination', function () {
    $mine = NotificationChannel::factory()->for($this->user)->create(['label' => 'Team Discord', 'apprise_url' => 'discord://secret-id/secret-token/']);
    $unverified = NotificationChannel::factory()->for($this->user)->create(['label' => 'New one', 'verified_at' => null]);
    NotificationChannel::factory()->create(['label' => 'Someone else']);
    $location = Location::factory()->for($this->user)->create(['label' => 'Home']);
    Location::factory()->create(['label' => 'Not mine']);
    $template = AlertTemplate::factory()->for($this->user)->create(['category' => AlertCategory::WeatherAlerts, 'name' => 'My template']);
    AlertTemplate::factory()->create(['category' => AlertCategory::WeatherAlerts, 'name' => 'Their template']);

    $response = optionsCall($this)->assertOk();

    expect(collect($response->json('data.channels'))->pluck('label')->all())->toBe(['New one', 'Team Discord'])
        ->and(collect($response->json('data.channels'))->firstWhere('id', $mine->id))->toMatchArray(['type' => 'discord', 'type_label' => 'Discord', 'verified' => true])
        ->and(collect($response->json('data.channels'))->firstWhere('id', $unverified->id)['verified'])->toBeFalse()
        ->and($response->json('data.locations'))->toBe([['id' => $location->id, 'label' => 'Home']])
        ->and($response->json('data.templates.weather_alerts'))->toBe([['id' => $template->id, 'name' => 'My template']])
        ->and($response->getContent())->not->toContain('secret-token')->not->toContain('Someone else')->not->toContain('Their template');
});

test('it says whether another location can be created', function () {
    $limits = app(TierThresholdSettings::class);
    $limits->free_locations = 1;
    $limits->save();

    expect(optionsCall($this)->json('data.can_create_location'))->toBeTrue();

    Location::factory()->for($this->user)->create();

    expect(optionsCall($this)->json('data.can_create_location'))->toBeFalse();
});

test('it says whether another phone location can be shared, apart from saved places', function () {
    $limits = app(TierThresholdSettings::class);
    $limits->free_locations = 1;
    $limits->free_phone_locations = 1;
    $limits->save();

    Location::factory()->for($this->user)->create();

    expect(optionsCall($this)->json('data.can_share_phone_location'))->toBeTrue();

    Location::factory()->for($this->user)->mobileApp()->create();

    $data = optionsCall($this)->json('data');
    expect($data['can_share_phone_location'])->toBeFalse()
        ->and($data['can_create_location'])->toBeFalse();
});

test('every product carries the modes its row offers and its default mode', function () {
    $tornado = Product::where('code', 'TO.W')->sole();
    $risk = Product::where('family', 'spc_wpc')->whereNotNull('risk_level_options')->orderBy('id')->firstOrFail();
    $plain = Product::where('family', 'spc_wpc')->whereNull('risk_level_options')->orderBy('id')->firstOrFail();
    $noPolygonSpc = Product::where('family', 'spc_wpc')->whereNull('risk_level_options')->where('id', '!=', $plain->id)->orderBy('id')->firstOrFail();
    $tornado->forceFill(['has_polygon' => true])->save();
    $plain->forceFill(['has_polygon' => true])->save();
    $noPolygonSpc->forceFill(['has_polygon' => false])->save();
    $noPolygon = Product::where('family', 'warning')->where('has_polygon', false)->where('status', 'active')->firstOrFail();

    $response = optionsCall($this)->assertOk();
    $find = fn (string $key, Product $product): array => collect($response->json("data.products.{$key}"))->pluck('products')->flatten(1)->firstWhere('id', $product->id);

    expect($find('weather_alerts', $tornado)['modes'])->toBe(['nationwide', 'location', 'wfo', 'county_zone', 'polygon'])
        ->and($find('weather_alerts', $tornado)['default_mode'])->toBe('polygon')
        ->and($find('weather_alerts', $noPolygon)['modes'])->toBe(['nationwide', 'location', 'wfo', 'county_zone'])
        ->and($find('weather_alerts', $noPolygon)['default_mode'])->toBe('location')
        ->and($find('spc_wpc', $risk)['modes'])->toBe(['nationwide', 'risk_area'])
        ->and($find('spc_wpc', $risk)['default_mode'])->toBe('risk_area')
        ->and($find('spc_wpc', $plain)['modes'])->toBe(['nationwide', 'polygon'])
        ->and($find('spc_wpc', $plain)['default_mode'])->toBe('polygon')
        ->and($find('spc_wpc', $noPolygonSpc)['modes'])->toBe(['nationwide'])
        ->and($find('spc_wpc', $noPolygonSpc)['default_mode'])->toBe('nationwide');
});

test('it reports the alert limit, the count and whether another alert or Outlook Report can be created', function () {
    $limits = app(TierThresholdSettings::class);
    $limits->free_alerts = 3;
    $limits->save();

    $data = optionsCall($this)->json('data');
    expect([$data['alert_limit'], $data['alert_count'], $data['can_create_alert'], $data['can_create_outlook_report']])->toBe([3, 0, true, true]);

    AlertRule::factory()->for($this->user)->create(['category' => AlertCategory::OutlookReport]);
    AlertRule::factory()->for($this->user)->create(['is_active' => false]);

    $data = optionsCall($this)->json('data');
    expect([$data['alert_count'], $data['can_create_alert'], $data['can_create_outlook_report']])->toBe([2, true, false]);

    AlertRule::factory()->for($this->user)->create();

    $data = optionsCall($this)->json('data');
    expect([$data['alert_count'], $data['can_create_alert'], $data['can_create_outlook_report']])->toBe([3, false, false]);
});

test('a Plus subscriber gets the Plus limit and can create Outlook Reports', function () {
    $plus = User::factory()->create(['tier_override' => Tier::Plus]);
    $token = $plus->createToken('Pixel 9', ['app'])->plainTextToken;
    AlertRule::factory()->for($plus)->create(['category' => AlertCategory::OutlookReport]);

    $data = optionsCall($this, $token)->json('data');

    expect($data['alert_limit'])->toBe(app(TierThresholdSettings::class)->plus_alerts)
        ->and($data['can_create_alert'])->toBeTrue()
        ->and($data['can_create_outlook_report'])->toBeTrue();
});

test('it lists the push sound kinds and sounds', function () {
    $sounds = optionsCall($this)->assertOk()->json('data.push_sounds');

    expect($sounds['kinds'])->toHaveCount(6)
        ->and($sounds['sounds'])->toHaveCount(7)
        ->and($sounds)->toBe(PushSounds::options());
});
