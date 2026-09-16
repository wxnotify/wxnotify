<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Resources\AlertRules\Pages\CreateAlertRule;
use App\Filament\Dashboard\Resources\AlertRules\Pages\EditAlertRule;
use App\Filament\Dashboard\Resources\AlertRules\Pages\ListAlertRules;
use App\Models\AlertRule;
use App\Models\AlertTemplate;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\ProductCatalogSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

beforeEach(function () {
    // Populates Product::category, which the weather-alerts/spc-wpc product
    // sections below are grouped by.
    $this->seed(ProductCatalogSeeder::class);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->location = Location::factory()->for($this->user)->create(['label' => 'Home']);
    $this->channel = NotificationChannel::factory()->for($this->user)->create();
});

test('a subscriber only sees their own alert rules', function () {
    $mine = AlertRule::factory()->for($this->user)->create();
    $someoneElses = AlertRule::factory()->for(User::factory())->create();

    livewire(ListAlertRules::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$someoneElses]);
});

test('creating a threshold alert persists the rule and its channel', function () {
    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'threshold',
            'variable' => 'temperature',
            'direction' => 'below',
            'threshold_value' => 32,
            'timeframe' => 'overnight',
            'location_id' => $this->location->id,
            'delivery_time' => '06:00',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->category->value)->toBe('threshold')
        ->and($rule->variable->value)->toBe('temperature')
        ->and($rule->direction->value)->toBe('below')
        ->and((float) $rule->threshold_value)->toBe(32.0)
        ->and($rule->location_id)->toBe($this->location->id)
        ->and($rule->displayName())->toBe('Temperature Falls below 32.0°F');

    expect($rule->notificationChannels->pluck('id')->all())->toBe([$this->channel->id]);
});

test('creating a weather alerts rule persists the chosen products and matching mode', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_ids' => [$tornadoWarning->category->value => [$tornadoWarning->id]],
            'matching_mode' => 'location',
            'location_id' => $this->location->id,
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->matching_mode->value)->toBe('location')
        ->and($rule->products->pluck('id')->all())->toBe([$tornadoWarning->id])
        ->and($rule->displayName())->toBe('Tornado Warning');
});

test('creating a nationwide weather alerts rule does not require a location', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_ids' => [$tornadoWarning->category->value => [$tornadoWarning->id]],
            'matching_mode' => 'nationwide',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->matching_mode->value)->toBe('nationwide')
        ->and($rule->location_id)->toBeNull();
});

test('a corrupted product_ids shape is rejected by validation instead of crashing or silently creating an empty rule', function () {
    // Regression: a real browser session hit a Livewire quirk where a
    // dynamically-appearing ToggleList field (only entering the schema
    // after `category` is picked — see
    // AlertRuleForm::seedProductIdsDefaults()'s docblock) started out
    // `null` instead of `[]`, causing the checkbox to bind as a single
    // boolean rather than a checkbox-group array. This reproduces the
    // exact corrupted shape captured from that real network request —
    // one touched category holding `true`, untouched siblings holding
    // `null` — and confirms it's now caught by validation, not left to
    // crash sync() with a Postgres bigint cast error on an empty string.
    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_ids' => [
                'tornado_and_severe_thunderstorm' => true,
                'flooding_and_hydrological' => null,
                'winter_weather' => null,
            ],
            'matching_mode' => 'nationwide',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors();

    expect(AlertRule::where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('a weather alerts rule can select products outside the graduated warning/watch/advisory/statement families', function () {
    // Regression: the product-picker sections used to filter by family
    // (warning/watch/advisory/statement only), which silently excluded
    // every administrative/informational/aviation product — entire
    // category sections (Airport-related, most of Other Forecasts...)
    // never appeared, and others (Non-Meteorological Hazards...) showed
    // only a handful of their products.
    $civilDanger = Product::where('code', 'CDW')->sole();
    expect($civilDanger->family->value)->toBe('administrative');

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_ids' => [$civilDanger->category->value => [$civilDanger->id]],
            'matching_mode' => 'nationwide',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->products->pluck('id')->all())->toBe([$civilDanger->id]);
});

test('creating an spc wpc rule persists the chosen risk levels', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'spc_wpc',
            'product_ids' => [$outlook->category->value => [$outlook->id]],
            'risk_levels' => ['Enhanced', 'Moderate', 'High'],
            'matching_mode' => 'nationwide',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->risk_levels)->toBe(['Enhanced', 'Moderate', 'High'])
        ->and($rule->products->pluck('id')->all())->toBe([$outlook->id]);
});

test('creating a lightning alert rejects a radius below the enforced minimum', function () {
    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'lightning',
            'location_id' => $this->location->id,
            'radius_miles' => 2,
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['radius_miles' => 'min']);

    expect(AlertRule::where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('creating a lightning alert at the minimum radius succeeds', function () {
    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'lightning',
            'location_id' => $this->location->id,
            'radius_miles' => 5,
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->radius_miles)->toBe(5)
        ->and($rule->displayName())->toBe('Lightning within 5 mi of Home');
});

test('creating a forecast discussion rule picks a real office from the WFO picker', function () {
    DB::table('cwa_boundaries')->insert([
        'wfo' => 'OKX', 'cwa' => 'OKX', 'city' => 'Upton', 'st' => 'NY', 'citystate' => 'Upton NY',
        'lon' => -72.5, 'lat' => 40.9,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'forecast_discussion',
            'wfo' => 'OKX',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->wfo)->toBe('OKX');
});

test('the WFO picker rejects an office not in the real cwa_boundaries table', function () {
    // A real row exists (OKX) so this proves "valid table, invalid value" --
    // not just "empty table, any value would fail."
    DB::table('cwa_boundaries')->insert([
        'wfo' => 'OKX', 'cwa' => 'OKX', 'city' => 'Upton', 'st' => 'NY', 'citystate' => 'Upton NY',
        'lon' => -72.5, 'lat' => 40.9,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'forecast_discussion',
            'wfo' => 'ZZZ',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['wfo']);
});

test('a Wfo-mode weather alerts rule persists the office selected from the picker', function () {
    DB::table('cwa_boundaries')->insert([
        'wfo' => 'BUF', 'cwa' => 'BUF', 'city' => 'Buffalo', 'st' => 'NY', 'citystate' => 'Buffalo NY',
        'lon' => -78.7, 'lat' => 42.9,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-79 42, -78 42, -78 43, -79 43, -79 42))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_ids' => [$tornadoWarning->category->value => [$tornadoWarning->id]],
            'matching_mode' => 'wfo',
            'wfo' => 'BUF',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->wfo)->toBe('BUF')
        ->and($rule->matching_mode->value)->toBe('wfo');
});

test('a subscriber cannot point an alert rule at another subscriber\'s location', function () {
    $othersLocation = Location::factory()->for(User::factory())->create();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'threshold',
            'variable' => 'temperature',
            'direction' => 'below',
            'threshold_value' => 32,
            'timeframe' => 'overnight',
            'location_id' => $othersLocation->id,
            'delivery_time' => '06:00',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['location_id']);

    expect(AlertRule::where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('creating a location inline from the location field attaches it to the alert', function () {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();

    $component = livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'lightning'])
        ->callFormComponentAction('location_id', 'createOption', data: [
            'label' => 'Office',
            'lat' => 38.8976,
            'lng' => -77.0365,
            'address' => '1600 Pennsylvania Ave NW, Washington, DC',
            // In production, TimezoneSelect's browser-JS auto-detect fills this
            // before submit; Pest's headless Livewire test environment never
            // runs that JS, so it must be supplied explicitly here.
            'timezone' => 'America/New_York',
        ]);

    $location = Location::where('user_id', $this->user->id)->where('label', 'Office')->sole();

    $component
        ->fillForm(['radius_miles' => 10, 'channel_ids' => [$this->channel->id]])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->location_id)->toBe($location->id);
});

test('the inline location action is hidden once a Free subscriber is at their location limit', function () {
    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'lightning'])
        ->assertFormComponentActionHidden('location_id', 'createOption');
});

test('connecting a channel inline from the channels field dispatches a code and attaches it to the alert', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    // beforeEach's $this->channel (discord) already fills the Free tier's one
    // no-cost slot, so a second no-cost type like ntfy needs a paid tier here.
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();

    $component = livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'lightning', 'channel_ids' => [$this->channel->id]])
        ->mountFormComponentAction('channel_ids', 'connect_ntfy')
        ->setFormComponentActionData(['topic' => 'wxnotify-inline-topic'])
        ->callMountedFormComponentAction();

    $newChannel = NotificationChannel::where('user_id', $this->user->id)
        ->where('channel_type', 'ntfy')
        ->sole();

    expect($newChannel->apprise_url)->toBe('ntfy://wxnotify-inline-topic')
        ->and($newChannel->isVerified())->toBeFalse()
        ->and($newChannel->confirmation_code)->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/notify/'));

    $component
        ->fillForm(['location_id' => $this->location->id, 'radius_miles' => 10])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->notificationChannels->pluck('id')->all())->toBe([$this->channel->id, $newChannel->id]);
});

test('the inline channel action for a no-cost type is hidden once a Free subscriber has used their slot', function () {
    // beforeEach's $this->channel is a discord channel, which fills the Free tier's one no-cost slot.
    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'lightning'])
        ->assertFormComponentActionHidden('channel_ids', 'connect_slack')
        ->assertFormComponentActionVisible('channel_ids', 'connect_email');
});

test('the CountyZone picker options include real counties and zones for the filtered state', function () {
    DB::table('counties')->insert([
        'state' => 'NY', 'cwa' => 'OKX', 'countyname' => 'Suffolk', 'fips' => '36103',
        'lon' => -72.5, 'lat' => 40.9,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('public_zones')->insert([
        'state' => 'NY', 'cwa' => 'OKX', 'zone' => '072', 'name' => 'Suffolk', 'state_zone' => 'NY072',
        'lon' => -72.5, 'lat' => 40.9,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_ids' => [$tornadoWarning->category->value => [$tornadoWarning->id]],
            'matching_mode' => 'county_zone',
            'county_zone_state' => 'NY',
            'county_zone_codes' => ['NYC103', 'NYZ072'],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->county_zone_codes)->toBe('NYC103,NYZ072');
});

test('the CountyZone picker requires at least one code', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_ids' => [$tornadoWarning->category->value => [$tornadoWarning->id]],
            'matching_mode' => 'county_zone',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['county_zone_codes']);
});

test('editing a CountyZone rule preserves its stored codes without requiring them to be re-picked', function () {
    // Regression: county_zone_codes is a multi-select over a plain
    // comma-joined string column, not an array/JSON-cast one -- Filament's
    // default multiple() state cast runs json_decode() on the stored value
    // during hydration, which silently turns a real string like
    // "NYZ072,NYC061" into [] (not valid JSON), wiping the field and
    // blocking save with a false "required" error. See
    // EditAlertRule::mutateFormDataBeforeFill() for the fix.
    //
    // The picker's Select validates submitted values against its live
    // options() list, so the stored codes need matching real shapefile
    // rows to be considered valid on save -- not just for display.
    DB::table('public_zones')->insert([
        'state' => 'NY', 'cwa' => 'OKX', 'zone' => '072', 'name' => 'Suffolk', 'state_zone' => 'NY072',
        'lon' => -72.5, 'lat' => 40.9,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('counties')->insert([
        'state' => 'NY', 'cwa' => 'OKX', 'countyname' => 'Suffolk', 'fips' => '36061',
        'lon' => -72.5, 'lat' => 40.9,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $rule = AlertRule::factory()->for($this->user)->create([
        'category' => 'weather_alerts',
        'matching_mode' => 'county_zone',
        'location_id' => null,
        'county_zone_codes' => 'NYZ072,NYC061',
    ]);
    $rule->products()->attach($tornadoWarning);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->assertFormSet(['county_zone_codes' => ['NYZ072', 'NYC061']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->county_zone_codes)->toBe('NYZ072,NYC061');
});

test('the forecast discussion wfo field defaults to the subscriber\'s most recent location\'s CWA', function () {
    DB::table('cwa_boundaries')->insert([
        'wfo' => 'OKX', 'cwa' => 'OKX', 'lon' => -72.5, 'lat' => 40.9,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->location->update(['lat' => 40.5, 'lng' => -72.5]);

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'forecast_discussion'])
        ->assertFormSet(['wfo' => 'OKX']);
});

test('a Free subscriber\'s wizard never shows the message customization fields', function () {
    $this->user->forceFill(['tier_override' => Tier::Free])->save();

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'threshold'])
        ->assertFormFieldHidden('message_body_style')
        ->assertFormFieldHidden('custom_subject_template');
});

test('a Plus subscriber can set and persist a custom subject and message length', function () {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'threshold',
            'variable' => 'temperature',
            'direction' => 'below',
            'threshold_value' => 32,
            'timeframe' => 'overnight',
            'location_id' => $this->location->id,
            'delivery_time' => '06:00',
            'channel_ids' => [$this->channel->id],
            'custom_subject_template' => 'Cold snap near {location}',
            'message_body_style' => 'brief',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();
    expect($rule->custom_subject_template)->toBe('Cold snap near {location}')
        ->and($rule->message_body_style->value)->toBe('brief');
});

test('editing an existing rule round-trips the custom subject and message length', function () {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();
    $rule = AlertRule::factory()->for($this->user)->for($this->location)->create([
        'custom_subject_template' => 'Original subject',
        'message_body_style' => 'brief',
    ]);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->assertFormSet([
            'custom_subject_template' => 'Original subject',
            'message_body_style' => 'brief',
        ]);
});

test('the alert_template_id select only offers templates matching the rule\'s own category', function () {
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($plusUser);
    $channel = NotificationChannel::factory()->for($plusUser)->create();

    $matching = AlertTemplate::factory()->for($plusUser)->create(['category' => 'weather_alerts', 'name' => 'Matching']);
    $mismatched = AlertTemplate::factory()->for($plusUser)->spcWpc()->create(['name' => 'Mismatched']);

    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_ids' => [$tornadoWarning->category->value => [$tornadoWarning->id]],
            'matching_mode' => 'nationwide',
            'channel_ids' => [$channel->id],
        ])
        // No assertFormFieldOptionsExist()/assertFormFieldOptionsDoNotExist()
        // exists in this installed Filament version (confirmed: grep for
        // "assertFormFieldOptions" across tests/ and
        // vendor/filament/forms/src/Testing/TestsForms.php turns up nothing).
        // assertFormFieldExists()'s $checkFieldUsing callback receives the
        // live, hydrated Select instance, so calling ->getOptions() on it
        // exercises the exact same options() closure -- and the exact same
        // Get $get resolution -- the real Livewire component would use.
        ->assertFormFieldExists('alert_template_id', function (Select $field) use ($matching, $mismatched): bool {
            $optionIds = array_keys($field->getOptions());

            return in_array($matching->id, $optionIds) && ! in_array($mismatched->id, $optionIds);
        });
});
