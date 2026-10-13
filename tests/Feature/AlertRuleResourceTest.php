<?php

use App\Enums\MatchingMode;
use App\Enums\ProductStatus;
use App\Enums\Tier;
use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use App\Filament\Dashboard\Resources\AlertRules\Pages\CreateAlertRule;
use App\Filament\Dashboard\Resources\AlertRules\Pages\EditAlertRule;
use App\Filament\Dashboard\Resources\AlertRules\Pages\ListAlertRules;
use App\Models\AlertRule;
use App\Models\AlertTemplate;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use Database\Seeders\ProductCatalogSeeder;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Jbuitt\FilamentToggleList\ToggleList;

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

test('the list page explains what it\'s for', function () {
    livewire(ListAlertRules::class)
        ->assertSee("Alerts you've set up to be notified about");
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

test('creating an alert rule redirects back to the list page, not the edit page', function () {
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
        ->assertHasNoFormErrors()
        ->assertRedirect(AlertRuleResource::getUrl('index'));
});

test('creating a weather alerts rule persists the chosen products and matching mode', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_ids' => [$tornadoWarning->category->value => [$tornadoWarning->id]],
            'matching_mode' => 'location',
            'location_ids' => [$this->location->id],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->matching_mode->value)->toBe('location')
        ->and($rule->locations->pluck('id')->all())->toBe([$this->location->id])
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
            'risk_levels' => ['convective_outlooks' => ['Enhanced', 'Moderate', 'High']],
            'matching_mode' => 'nationwide',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->risk_levels)->toBe(['Enhanced', 'Moderate', 'High'])
        ->and($rule->products->pluck('id')->all())->toBe([$outlook->id]);
});

test('an spc wpc rule for the Convective Outlook can use RiskArea matching mode against a saved location', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $location = Location::factory()->for($this->user)->create();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'spc_wpc',
            'product_ids' => [$outlook->category->value => [$outlook->id]],
            'risk_levels' => ['convective_outlooks' => ['Moderate']],
            'matching_mode' => 'risk_area',
            'location_ids' => [$location->id],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->matching_mode)->toBe(MatchingMode::RiskArea)
        ->and($rule->locations->pluck('id')->all())->toBe([$location->id]);
});

test('RiskArea is not offered as a matching mode for weather_alerts -- it only applies to spc_wpc', function () {
    // Regression: matchingModeFields()'s default branch (every non-spc_wpc
    // category) used to return MatchingMode::cases() unfiltered, which
    // started including RiskArea the moment that case was added for SPC/WPC
    // -- "By risk area at my location" leaked into Weather Alerts' picker
    // even though its threshold logic (risk_levels) only exists on the
    // spc_wpc branch.
    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'weather_alerts'])
        ->assertFormFieldExists('matching_mode', function (Radio $field): bool {
            $optionValues = array_keys($field->getOptions());

            return ! in_array(MatchingMode::RiskArea->value, $optionValues, true)
                && in_array(MatchingMode::Polygon->value, $optionValues, true);
        });
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
            'radius_miles' => 10,
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->radius_miles)->toBe(10)
        ->and($rule->displayName())->toBe('Lightning within 10 mi of Home');
});

test('creating a Weather Alerts rule for the Area Forecast Discussion picks a real office from the WFO picker', function () {
    DB::table('cwa_boundaries')->insert([
        'wfo' => 'OKX', 'cwa' => 'OKX', 'city' => 'Upton', 'st' => 'NY', 'citystate' => 'Upton NY',
        'lon' => -72.5, 'lat' => 40.9,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $afd = Product::where('code', 'AFD')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_ids' => [$afd->category->value => [$afd->id]],
            'matching_mode' => 'wfo',
            'wfo' => 'OKX',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->wfo)->toBe('OKX')
        ->and($rule->category->value)->toBe('weather_alerts')
        ->and($rule->products->pluck('code')->all())->toBe(['AFD']);
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

    $afd = Product::where('code', 'AFD')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_ids' => [$afd->category->value => [$afd->id]],
            'matching_mode' => 'wfo',
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

    // The pivot query carries no explicit orderBy(), so the DB is free to
    // return the two attached channels in either order -- compare as a set.
    expect($rule->notificationChannels->pluck('id')->all())->toEqualCanonicalizing([$this->channel->id, $newChannel->id]);
});

test('the inline channel action for a no-cost type is hidden once a Free subscriber has used their slot', function () {
    // beforeEach's $this->channel is a discord channel, which fills the Free tier's one no-cost slot.
    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'lightning'])
        ->assertFormComponentActionHidden('channel_ids', 'connect_slack')
        ->assertFormComponentActionVisible('channel_ids', 'connect_email');
});

test('channel types that cannot be created inline get no suffix action on the channels field', function () {
    // Webhook and Web Push both return supportsInlineCreate() === false: a
    // webhook has to show its signing secret once on a Confirm step, and Web
    // Push needs the browser's own subscribe flow, so neither fits a one-shot
    // modal -- the full ConnectChannel wizard is the only way in. VAPID keys
    // are configured and the tier is raised so both types really are in the
    // picker; otherwise isAvailable()/canAddChannel() would filter them out and
    // this test would pass for the wrong reason.
    chanConfigureVapid();
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();

    expect(app(ChannelTypeRegistry::class)->forPicker()->map(fn (array $row) => $row['type']->slug())->all())
        ->toContain('webhook')
        ->toContain('webpush');

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'lightning'])
        ->assertFormComponentActionDoesNotExist('channel_ids', 'connect_webhook')
        ->assertFormComponentActionDoesNotExist('channel_ids', 'connect_webpush')
        ->assertFormComponentActionExists('channel_ids', 'connect_discord');
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

test('the Area Forecast Discussion is offered under Weather Alerts, in the Other Forecasts group', function () {
    $afd = Product::where('code', 'AFD')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'weather_alerts'])
        ->assertFormFieldExists("product_ids.{$afd->category->value}", fn (ToggleList $field): bool => array_key_exists($afd->id, $field->getOptions()));
});

test('Forecast Discussions is no longer a category in the wizard', function () {
    livewire(CreateAlertRule::class)
        ->assertFormFieldExists('category', fn (Radio $field): bool => ! array_key_exists('forecast_discussion', $field->getOptions())
            && count($field->getOptions()) === 5);
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

test('changing an alert rule\'s category away from weather_alerts clears a stale alert_template_id', function () {
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($plusUser);
    $location = Location::factory()->for($plusUser)->create();
    $channel = NotificationChannel::factory()->for($plusUser)->create();

    $template = AlertTemplate::factory()->for($plusUser)->create(['category' => 'weather_alerts']);
    $rule = AlertRule::factory()->for($plusUser)->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
        'alert_template_id' => $template->id,
    ]);
    $rule->notificationChannels()->attach($channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->set('data.category', 'daily_summary')
        // daily_summary's own required location_id -- unlike category and
        // alert_template_id, this one isn't seeded automatically by any
        // afterStateUpdated() hook, so the switch needs it set explicitly
        // for save() to pass validation; it's incidental to what this test
        // is actually verifying (that alert_template_id gets cleared).
        ->set('data.location_id', $location->id)
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->alert_template_id)->toBeNull();
});

test('creating a weather alerts rule with two locations persists both via the pivot', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $secondLocation = Location::factory()->for($this->user)->create();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_ids' => [$tornadoWarning->category->value => [$tornadoWarning->id]],
            'matching_mode' => 'location',
            'location_ids' => [$this->location->id, $secondLocation->id],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->locations->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->location->id, $secondLocation->id])->sort()->values()->all());
});

test('editing an existing multi-location rule pre-fills every attached location, and saving without changes keeps them all', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $secondLocation = Location::factory()->for($this->user)->create();
    $rule = AlertRule::factory()->for($this->user)->create(['category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => null]);
    $rule->products()->attach($tornadoWarning);
    $rule->locations()->attach([$this->location->id, $secondLocation->id]);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->assertFormSet(['location_ids' => [$this->location->id, $secondLocation->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->locations->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->location->id, $secondLocation->id])->sort()->values()->all());
});

test('editing a multi-location rule down to one location detaches the rest', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $secondLocation = Location::factory()->for($this->user)->create();
    $rule = AlertRule::factory()->for($this->user)->create(['category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => null]);
    $rule->products()->attach($tornadoWarning);
    $rule->locations()->attach([$this->location->id, $secondLocation->id]);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        // ->set('data.X', value), not ->fillForm([...]) -- this file's own
        // EditAlertRule tests consistently change one field this way (see
        // 'alert_template_id gets cleared' further down), not fillForm(),
        // which this file reserves for CreateAlertRule's initial fill.
        ->set('data.location_ids', [$this->location->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->locations->pluck('id')->all())->toBe([$this->location->id]);
});

test('a subscriber cannot attach another subscriber\'s location via location_ids', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $othersLocation = Location::factory()->for(User::factory())->create();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_ids' => [$tornadoWarning->category->value => [$tornadoWarning->id]],
            'matching_mode' => 'location',
            'location_ids' => [$othersLocation->id],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['location_ids']);

    expect(AlertRule::where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('a threshold value too large to store is refused', function (int $value) {
    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'threshold',
            'variable' => 'temperature',
            'direction' => 'below',
            'threshold_value' => $value,
            'timeframe' => 'overnight',
            'location_id' => $this->location->id,
            'delivery_time' => '06:00',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['threshold_value']);

    expect(AlertRule::where('user_id', $this->user->id)->exists())->toBeFalse();
})->with([1000000, -1000000]);

test('saving changes to an alert returns the subscriber to the alerts list', function () {
    $rule = AlertRule::factory()->for($this->user)->for($this->location)->create(['threshold_value' => 32]);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->fillForm(['threshold_value' => 28])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(AlertRuleResource::getUrl('index'));

    expect($rule->fresh()->threshold_value)->toBe('28.0');
});

test('a save that fails validation stays on the edit page', function () {
    $rule = AlertRule::factory()->for($this->user)->for($this->location)->create();
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->fillForm(['channel_ids' => []])
        ->call('save')
        ->assertHasFormErrors(['channel_ids'])
        ->assertNoRedirect();
});

test('each SPC & WPC category with risk-level products lists its own risk options', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $fire = Product::where('code', 'SPC.FIRE')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'spc_wpc',
            'product_ids' => ['fire_weather' => [$fire->id], 'convective_outlooks' => [$outlook->id]],
        ])
        ->assertFormFieldExists('risk_levels.fire_weather', fn (CheckboxList $field): bool => array_keys($field->getOptions()) === ['Elevated', 'Critical', 'Extremely Critical', 'Isolated Dry T-Storms', 'Scattered Dry T-Storms'])
        ->assertFormFieldExists('risk_levels.convective_outlooks', fn (CheckboxList $field): bool => array_keys($field->getOptions()) === ['Marginal', 'Slight', 'Enhanced', 'Moderate', 'High'])
        ->assertFormFieldVisible('risk_levels.fire_weather')
        ->assertFormFieldVisible('risk_levels.convective_outlooks');
});

test('a category\'s risk levels appear only while a product with risk levels is selected in it', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $day2 = Product::where('code', 'DY2')->sole();
    $fire = Product::where('code', 'SPC.FIRE')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'spc_wpc', 'product_ids' => ['convective_outlooks' => [$day2->id]]])
        ->assertFormFieldHidden('risk_levels.convective_outlooks')
        ->assertFormFieldHidden('risk_levels.fire_weather')
        ->fillForm(['product_ids' => ['convective_outlooks' => [$outlook->id]]])
        ->assertFormFieldVisible('risk_levels.convective_outlooks')
        ->assertFormFieldHidden('risk_levels.fire_weather')
        ->fillForm(['product_ids' => ['convective_outlooks' => [], 'fire_weather' => [$fire->id]]])
        ->assertFormFieldHidden('risk_levels.convective_outlooks')
        ->assertFormFieldVisible('risk_levels.fire_weather');
});

test('the Fire Weather list says fire levels do not filter yet, the Convective list says it applies to Day 1', function () {
    $fire = Product::where('code', 'SPC.FIRE')->sole();
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'spc_wpc', 'product_ids' => ['fire_weather' => [$fire->id], 'convective_outlooks' => [$outlook->id]]])
        ->assertSee("Fire risk levels are saved, but they don't filter alerts yet.")
        ->assertSee('Risk levels apply to the Day 1 Convective Outlook.');
});

test('categories without risk levels never get a risk list', function () {
    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'spc_wpc'])
        ->assertFormFieldDoesNotExist('risk_levels.flooding_and_hydrological')
        ->assertFormFieldDoesNotExist('risk_levels.other_discussions_reports_and_outlooks');
});

test('creating an SPC & WPC rule with ticks in two categories stores one flat list', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $fire = Product::where('code', 'SPC.FIRE')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'spc_wpc',
            'product_ids' => ['fire_weather' => [$fire->id], 'convective_outlooks' => [$outlook->id]],
            'risk_levels' => ['fire_weather' => ['Critical'], 'convective_outlooks' => ['Moderate', 'High']],
            'matching_mode' => 'nationwide',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AlertRule::where('user_id', $this->user->id)->sole()->risk_levels)->toBe(['Critical', 'Moderate', 'High']);
});

test('ticks in a category whose products were deselected are not stored', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $fire = Product::where('code', 'SPC.FIRE')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'spc_wpc',
            'product_ids' => ['fire_weather' => [$fire->id], 'convective_outlooks' => [$outlook->id]],
            'risk_levels' => ['fire_weather' => ['Critical'], 'convective_outlooks' => ['High']],
            'matching_mode' => 'nationwide',
            'channel_ids' => [$this->channel->id],
        ])
        ->fillForm(['product_ids' => ['fire_weather' => [], 'convective_outlooks' => [$outlook->id]]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AlertRule::where('user_id', $this->user->id)->sole()->risk_levels)->toBe(['High']);
});

test('editing an SPC & WPC rule restores each category\'s ticks from the flat list, and saves them back flat', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $fire = Product::where('code', 'SPC.FIRE')->sole();
    $rule = AlertRule::factory()->for($this->user)->create([
        'category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null,
        'risk_levels' => ['Elevated', 'Moderate'],
    ]);
    $rule->products()->attach([$outlook->id, $fire->id]);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->assertFormSet(['risk_levels' => ['fire_weather' => ['Elevated'], 'convective_outlooks' => ['Moderate']]])
        ->fillForm(['risk_levels' => ['fire_weather' => ['Elevated'], 'convective_outlooks' => ['Moderate', 'High']]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->refresh()->risk_levels)->toBe(['Elevated', 'Moderate', 'High']);
});

test('a rule saved before this change with only Convective levels edits and saves unchanged', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $rule = AlertRule::factory()->for($this->user)->create([
        'category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null,
        'risk_levels' => ['Enhanced', 'High'],
    ]);
    $rule->products()->attach($outlook);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->assertFormSet(['risk_levels' => ['fire_weather' => [], 'convective_outlooks' => ['Enhanced', 'High']]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->refresh()->risk_levels)->toBe(['Enhanced', 'High']);
});

test('an SPC & WPC rule with no ticks stores an empty list, meaning every level', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'spc_wpc',
            'product_ids' => ['convective_outlooks' => [$outlook->id]],
            'matching_mode' => 'nationwide',
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AlertRule::where('user_id', $this->user->id)->sole()->risk_levels)->toBe([]);
});

test('deselecting every product with risk levels while editing clears the stored levels', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $day2 = Product::where('code', 'DY2')->sole();
    $rule = AlertRule::factory()->for($this->user)->create([
        'category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null,
        'risk_levels' => ['High'],
    ]);
    $rule->products()->attach($outlook);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->fillForm(['product_ids' => ['convective_outlooks' => [$day2->id]]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->refresh()->risk_levels)->toBe([]);
});

test('a product without risk levels selected beside one with them adds no options to the Convective list', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $day2 = Product::where('code', 'DY2')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'spc_wpc', 'product_ids' => ['convective_outlooks' => [$outlook->id, $day2->id]]])
        ->assertFormFieldVisible('risk_levels.convective_outlooks')
        ->assertFormFieldExists('risk_levels.convective_outlooks', fn (CheckboxList $field): bool => array_keys($field->getOptions()) === ['Marginal', 'Slight', 'Enhanced', 'Moderate', 'High']);
});

test('the Weather Alerts form never offers a risk checklist, even for a product that has risk level options', function () {
    $tornado = Product::where('code', 'TO.W')->sole();
    $tornado->update(['risk_level_options' => ['Low', 'High']]);

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'weather_alerts', 'product_ids' => [$tornado->category->value => [$tornado->id]]])
        ->assertFormFieldDoesNotExist("risk_levels.{$tornado->category->value}");
});

test('an untouched edit keeps the stored levels when their only risk product is no longer active', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $rule = AlertRule::factory()->for($this->user)->create([
        'category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null,
        'risk_levels' => ['High'],
    ]);
    $rule->products()->attach($outlook);
    $rule->notificationChannels()->attach($this->channel);
    $outlook->update(['status' => ProductStatus::Draft]);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->refresh()->risk_levels)->toBe(['High']);
});

test('the Set it up step has a scroll-to-bottom button for every category and none before a category is chosen', function (string $category) {
    livewire(CreateAlertRule::class)
        ->fillForm(['category' => $category])
        ->assertSeeHtml('aria-label="Scroll to bottom"');
})->with(['weather_alerts', 'spc_wpc', 'threshold', 'daily_summary', 'lightning']);

test('the scroll-to-bottom button is absent until a category is chosen', function () {
    livewire(CreateAlertRule::class)
        ->assertDontSeeHtml('aria-label="Scroll to bottom"');
});
