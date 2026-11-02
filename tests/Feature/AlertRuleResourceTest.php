<?php

use App\Actions\Alerts\BuildAlertRuleOptions;
use App\Actions\Alerts\SaveAlertRule;
use App\Enums\MatchingMode;
use App\Enums\ProductStatus;
use App\Enums\Tier;
use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use App\Filament\Dashboard\Resources\AlertRules\Pages\CreateAlertRule;
use App\Filament\Dashboard\Resources\AlertRules\Pages\EditAlertRule;
use App\Filament\Dashboard\Resources\AlertRules\Pages\ListAlertRules;
use App\Filament\Dashboard\Resources\AlertRules\Schemas\ProductMatchingTable;
use App\Models\AlertRule;
use App\Models\AlertTemplate;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\SubjectPlaceholders;
use Database\Seeders\ProductCatalogSeeder;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
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
            "product_settings.{$tornadoWarning->category->value}" => [alertWizardRow($tornadoWarning, 'location', ['location_ids' => [$this->location->id]])],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->settingsFor($tornadoWarning->id)->mode->value)->toBe('location')
        ->and($rule->settingsFor($tornadoWarning->id)->inherited)->toBeFalse()
        ->and($rule->settingsFor($tornadoWarning->id)->locations->pluck('id')->all())->toBe([$this->location->id])
        ->and($rule->products->pluck('id')->all())->toBe([$tornadoWarning->id])
        ->and($rule->only(['matching_mode', 'wfo', 'county_zone_codes']))->toBe(['matching_mode' => null, 'wfo' => null, 'county_zone_codes' => null])
        ->and($rule->locations)->toBeEmpty()
        ->and($rule->displayName())->toBe('Tornado Warning');
});

test('creating a nationwide weather alerts rule does not require a location', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            "product_settings.{$tornadoWarning->category->value}" => [alertWizardRow($tornadoWarning, 'nationwide')],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->settingsFor($tornadoWarning->id)->mode->value)->toBe('nationwide')
        ->and($rule->settingsFor($tornadoWarning->id)->locations)->toBeEmpty()
        ->and($rule->location_id)->toBeNull();
});

test('a corrupted product_settings shape is rejected by validation instead of crashing or silently creating an empty rule', function () {
    // Regression: a real browser session once hit a Livewire quirk where a
    // dynamically-appearing product field started out `null` instead of an
    // array, so a checkbox bound as a single boolean. This reproduces that
    // corrupted shape (one touched category holding `true`, untouched
    // siblings holding `null`, plus a row that is not an array) and confirms
    // it is caught by validation, not left to crash the render or the sync.
    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_settings' => [
                'tornado_and_severe_thunderstorm' => true,
                'flooding_and_hydrological' => null,
                'winter_weather' => ['not a row'],
            ],
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
            "product_settings.{$civilDanger->category->value}" => [alertWizardRow($civilDanger, 'nationwide')],
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
            "product_settings.{$outlook->category->value}" => [alertWizardRow($outlook, 'nationwide')],
            'risk_levels' => ['convective_outlooks' => ['Enhanced', 'Moderate', 'High']],
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
            "product_settings.{$outlook->category->value}" => [alertWizardRow($outlook, 'risk_area', ['location_ids' => [$location->id]])],
            'risk_levels' => ['convective_outlooks' => ['Moderate']],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->settingsFor($outlook->id)->mode)->toBe(MatchingMode::RiskArea)
        ->and($rule->settingsFor($outlook->id)->locations->pluck('id')->all())->toBe([$location->id]);
});

test('RiskArea is not offered as a matching mode for weather_alerts -- it only applies to spc_wpc -- and By product polygon only for a product with a polygon', function () {
    // Regression: the Weather Alerts mode picker once returned
    // MatchingMode::cases() unfiltered, which started including RiskArea the
    // moment that case was added for SPC/WPC -- "By risk area at my location"
    // leaked into Weather Alerts even though its threshold logic
    // (risk_levels) only exists for SPC & WPC. Every row is checked.
    $component = livewire(CreateAlertRule::class)->fillForm(['category' => 'weather_alerts']);

    foreach (Product::whereIn('family', BuildAlertRuleOptions::WEATHER_FAMILIES)->where('status', 'active')->pluck('category')->unique() as $category) {
        $component->assertFormFieldExists("product_settings.{$category->value}", function (ProductMatchingTable $field): bool {
            return collect($field->getState())->every(function (array $row) use ($field): bool {
                $optionValues = array_keys($field->modeOptionsFor($row));
                $hasPolygon = (bool) Product::findOrFail($row['product_id'])->has_polygon;

                return ! in_array(MatchingMode::RiskArea->value, $optionValues, true)
                    && in_array(MatchingMode::Polygon->value, $optionValues, true) === $hasPolygon;
            });
        });
    }
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
            "product_settings.{$afd->category->value}" => [alertWizardRow($afd, 'wfo', ['wfo' => 'OKX'])],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->settingsFor($afd->id)->wfo)->toBe('OKX')
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
            "product_settings.{$afd->category->value}" => [alertWizardRow($afd, 'wfo', ['wfo' => 'ZZZ'])],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(["product_settings.{$afd->category->value}"])
        ->assertSee('Choose a known NWS office for Area Forecast Discussion.');

    expect(AlertRule::where('user_id', $this->user->id)->exists())->toBeFalse();
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
            // Typed in lower case into the datalist input: stored as the office's code.
            "product_settings.{$tornadoWarning->category->value}" => [alertWizardRow($tornadoWarning, 'wfo', ['wfo' => ' buf'])],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->settingsFor($tornadoWarning->id)->wfo)->toBe('BUF')
        ->and($rule->settingsFor($tornadoWarning->id)->mode->value)->toBe('wfo');
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

test('the Choose zones modal offers real counties and zones for the filtered state and writes them to that row', function () {
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
    $severe = Product::where('code', 'SV.W')->sole();
    $table = "product_settings.{$tornadoWarning->category->value}";

    $component = livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'weather_alerts', 'channel_ids' => [$this->channel->id]])
        ->mountFormComponentAction($table, 'chooseZones', ['product' => $tornadoWarning->id])
        ->assertFormFieldExists('county_zone_codes', 'mountedActionSchema0', fn (Select $field): bool => $field->getOptions() === [])
        ->fillForm(['county_zone_state' => 'NY'], 'mountedActionSchema0')
        ->assertFormFieldExists('county_zone_codes', 'mountedActionSchema0', fn (Select $field): bool => array_keys($field->getOptions()) === ['NYC103', 'NYZ072'])
        ->fillForm(['county_zone_codes' => ['NYC103', 'NYZ072']], 'mountedActionSchema0')
        ->callMountedFormComponentAction()
        ->assertHasNoFormComponentActionErrors();

    $tornadoIndex = alertWizardRowIndex($component, $tornadoWarning);

    expect($component->get("data.{$table}.{$tornadoIndex}.county_zone_codes"))->toBe(['NYC103', 'NYZ072'])
        ->and($component->get("data.{$table}.".alertWizardRowIndex($component, $severe).'.county_zone_codes'))->toBe([]);

    $component
        ->set("data.{$table}.{$tornadoIndex}.enabled", true)
        ->set("data.{$table}.{$tornadoIndex}.matching_mode", 'county_zone')
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->settingsFor($tornadoWarning->id)->countyZoneCodes)->toBe(['NYC103', 'NYZ072']);
});

test('the CountyZone picker requires at least one code', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            "product_settings.{$tornadoWarning->category->value}" => [alertWizardRow($tornadoWarning, 'county_zone')],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(["product_settings.{$tornadoWarning->category->value}"])
        ->assertSee('Choose at least one county or zone for Tornado Warning.');

    expect(AlertRule::where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('editing a CountyZone rule preserves its stored codes without requiring them to be re-picked', function () {
    // Regression: county_zone_codes are stored as a comma-joined string, and
    // an earlier hydration turned "NYZ072,NYC061" into [] (not valid JSON),
    // wiping the codes and blocking save with a false "required" error. The
    // rule here is an old-style one (its products inherit the rule's own
    // codes), so this also proves the inherited codes reach the row.
    //
    // Saving validates every code against the real shapefile rows, so the
    // stored codes need matching rows to be valid on save.
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

    $component = livewire(EditAlertRule::class, ['record' => $rule->id]);
    $index = alertWizardRowIndex($component, $tornadoWarning);

    $component
        ->assertFormSet(["product_settings.{$tornadoWarning->category->value}.{$index}" => alertWizardRow($tornadoWarning, 'county_zone', ['county_zone_codes' => ['NYZ072', 'NYC061']])])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->settingsFor($tornadoWarning->id)->countyZoneCodes)->toBe(['NYZ072', 'NYC061'])
        ->and($rule->fresh()->settingsFor($tornadoWarning->id)->inherited)->toBeFalse();
});

test('the Area Forecast Discussion is offered under Weather Alerts, in the Other Forecasts group', function () {
    $afd = Product::where('code', 'AFD')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'weather_alerts'])
        ->assertFormFieldExists("product_settings.{$afd->category->value}", fn (ProductMatchingTable $field): bool => $field->getProducts()->has($afd->id)
            && collect($field->getState())->contains('product_id', $afd->id));
});

test('Forecast Discussions is no longer a category in the wizard', function () {
    livewire(CreateAlertRule::class)
        ->assertFormFieldExists('category', fn (Radio $field): bool => ! array_key_exists('forecast_discussion', $field->getOptions())
            && count($field->getOptions()) === 6);
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
            "product_settings.{$tornadoWarning->category->value}" => [alertWizardRow($tornadoWarning, 'nationwide')],
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
            // Ids arrive from the browser as strings.
            "product_settings.{$tornadoWarning->category->value}" => [alertWizardRow($tornadoWarning, 'location', ['location_ids' => [(string) $this->location->id, (string) $secondLocation->id]])],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->settingsFor($tornadoWarning->id)->locations->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->location->id, $secondLocation->id])->sort()->values()->all());
});

test('editing an existing multi-location rule pre-fills every attached location, and saving without changes keeps them all', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $secondLocation = Location::factory()->for($this->user)->create();
    $rule = AlertRule::factory()->for($this->user)->create(['category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => null]);
    $rule->products()->attach($tornadoWarning);
    $rule->locations()->attach([$this->location->id, $secondLocation->id]);
    $rule->notificationChannels()->attach($this->channel);

    $component = livewire(EditAlertRule::class, ['record' => $rule->id]);
    $index = alertWizardRowIndex($component, $tornadoWarning);

    $component
        ->assertFormSet(["product_settings.{$tornadoWarning->category->value}.{$index}" => alertWizardRow($tornadoWarning, 'location', ['location_ids' => [$this->location->id, $secondLocation->id]])])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->settingsFor($tornadoWarning->id)->locations->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->location->id, $secondLocation->id])->sort()->values()->all());
});

test('editing a rule saved per product pre-fills its product\'s row with its mode and locations, and saving keeps them', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $secondLocation = Location::factory()->for($this->user)->create();
    $rule = app(SaveAlertRule::class)($this->user, [
        'category' => 'weather_alerts',
        'product_ids' => [$tornadoWarning->id],
        'matching_mode' => 'polygon',
        'location_ids' => [$this->location->id, $secondLocation->id],
        'channel_ids' => [$this->channel->id],
    ]);

    $component = livewire(EditAlertRule::class, ['record' => $rule->id]);
    $index = alertWizardRowIndex($component, $tornadoWarning);

    $component
        ->assertFormSet(["product_settings.{$tornadoWarning->category->value}.{$index}" => alertWizardRow($tornadoWarning, 'polygon', ['location_ids' => [$this->location->id, $secondLocation->id]])])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = $rule->fresh()->settingsFor($tornadoWarning->id);

    expect($settings->mode)->toBe(MatchingMode::Polygon)
        ->and($settings->inherited)->toBeFalse()
        ->and($settings->locations->pluck('id')->all())->toBe([$this->location->id, $secondLocation->id]);
});

test('editing a multi-location rule down to one location detaches the rest', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $secondLocation = Location::factory()->for($this->user)->create();
    $rule = AlertRule::factory()->for($this->user)->create(['category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => null]);
    $rule->products()->attach($tornadoWarning);
    $rule->locations()->attach([$this->location->id, $secondLocation->id]);
    $rule->notificationChannels()->attach($this->channel);

    $component = livewire(EditAlertRule::class, ['record' => $rule->id]);
    $index = alertWizardRowIndex($component, $tornadoWarning);

    $component
        // ->set('data.X', value), not ->fillForm([...]) -- this file's own
        // EditAlertRule tests consistently change one field this way (see
        // 'alert_template_id gets cleared' further down), not fillForm(),
        // which this file reserves for CreateAlertRule's initial fill.
        ->set("data.product_settings.{$tornadoWarning->category->value}.{$index}.location_ids", [(string) $this->location->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->settingsFor($tornadoWarning->id)->locations->pluck('id')->all())->toBe([$this->location->id]);
});

test('a subscriber cannot attach another subscriber\'s location to a product', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $othersLocation = Location::factory()->for(User::factory())->create();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            "product_settings.{$tornadoWarning->category->value}" => [alertWizardRow($tornadoWarning, 'polygon', ['location_ids' => [$this->location->id, $othersLocation->id]])],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(["product_settings.{$tornadoWarning->category->value}"])
        ->assertSee('Choose only your own saved locations for Tornado Warning.');

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
            'product_settings.fire_weather' => [alertWizardRow($fire, 'nationwide')],
            'product_settings.convective_outlooks' => [alertWizardRow($outlook, 'nationwide')],
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
        ->fillForm(['category' => 'spc_wpc', 'product_settings.convective_outlooks' => [alertWizardRow($day2, 'nationwide'), alertWizardRow($outlook, 'nationwide', ['enabled' => false])]])
        ->assertFormFieldHidden('risk_levels.convective_outlooks')
        ->assertFormFieldHidden('risk_levels.fire_weather')
        ->fillForm(['product_settings.convective_outlooks' => [alertWizardRow($outlook, 'nationwide')]])
        ->assertFormFieldVisible('risk_levels.convective_outlooks')
        ->assertFormFieldHidden('risk_levels.fire_weather')
        ->fillForm(['product_settings.convective_outlooks' => [alertWizardRow($outlook, 'nationwide', ['enabled' => false])], 'product_settings.fire_weather' => [alertWizardRow($fire, 'nationwide')]])
        ->assertFormFieldHidden('risk_levels.convective_outlooks')
        ->assertFormFieldVisible('risk_levels.fire_weather');
});

test('the Fire Weather list says fire levels do not filter yet, the Convective list says it applies to Day 1', function () {
    $fire = Product::where('code', 'SPC.FIRE')->sole();
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'spc_wpc', 'product_settings.fire_weather' => [alertWizardRow($fire, 'nationwide')], 'product_settings.convective_outlooks' => [alertWizardRow($outlook, 'nationwide')]])
        ->assertSee("Fire risk levels are saved, but they don't filter alerts yet.")
        ->assertSee('Risk levels apply to the Day 1 Convective Outlook.');
});

test('a category whose products offer no risk levels never gets a risk list; Flooding gets the Excessive Rainfall Outlook\'s', function () {
    $discussion = Product::where('code', 'SPC.MD')->sole();
    $rainfall = Product::where('code', 'WPC.ERO')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'spc_wpc',
            'product_settings.other_discussions_reports_and_outlooks' => [alertWizardRow($discussion, 'nationwide')],
            'product_settings.flooding_and_hydrological' => [alertWizardRow($rainfall, 'nationwide')],
        ])
        ->assertSchemaComponentDoesNotExist('risk_levels.other_discussions_reports_and_outlooks')
        ->assertFormFieldExists('risk_levels.flooding_and_hydrological', fn (CheckboxList $field): bool => array_keys($field->getOptions()) === ['Marginal', 'Slight', 'Moderate', 'High'])
        ->assertFormFieldVisible('risk_levels.flooding_and_hydrological');
});

test('creating an SPC & WPC rule with ticks in two categories stores one flat list', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $fire = Product::where('code', 'SPC.FIRE')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'spc_wpc',
            'product_settings.fire_weather' => [alertWizardRow($fire, 'nationwide')],
            'product_settings.convective_outlooks' => [alertWizardRow($outlook, 'nationwide')],
            'risk_levels' => ['fire_weather' => ['Critical'], 'convective_outlooks' => ['Moderate', 'High']],
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
            'product_settings.fire_weather' => [alertWizardRow($fire, 'nationwide')],
            'product_settings.convective_outlooks' => [alertWizardRow($outlook, 'nationwide')],
            'risk_levels' => ['fire_weather' => ['Critical'], 'convective_outlooks' => ['High']],
            'channel_ids' => [$this->channel->id],
        ])
        ->fillForm(['product_settings.fire_weather' => [alertWizardRow($fire, 'nationwide', ['enabled' => false])]])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AlertRule::where('user_id', $this->user->id)->sole()->risk_levels)->toBe(['High']);
});

test('editing an SPC & WPC rule restores each category\'s ticks from its own products, and saves them per product', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $fire = Product::where('code', 'SPC.FIRE')->sole();
    $rule = AlertRule::factory()->for($this->user)->create([
        'category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null,
        'risk_levels' => ['Elevated', 'Moderate'],
    ]);
    $rule->products()->attach([$outlook->id, $fire->id]);
    $rule->notificationChannels()->attach($this->channel);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->assertFormSet(['risk_levels' => ['flooding_and_hydrological' => [], 'fire_weather' => ['Elevated'], 'convective_outlooks' => ['Moderate']]])
        ->fillForm(['risk_levels' => ['flooding_and_hydrological' => [], 'fire_weather' => ['Elevated'], 'convective_outlooks' => ['Moderate', 'High']]])
        ->call('save')
        ->assertHasNoFormErrors();

    $rule->refresh();
    expect($rule->riskLevelsFor($outlook->id))->toBe(['Moderate', 'High'])
        ->and($rule->riskLevelsFor($fire->id))->toBe(['Elevated'])
        ->and($rule->risk_levels)->toBe(['Elevated', 'Moderate', 'High']);
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
        ->assertFormSet(['risk_levels' => ['flooding_and_hydrological' => [], 'fire_weather' => [], 'convective_outlooks' => ['Enhanced', 'High']]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->refresh()->risk_levels)->toBe(['Enhanced', 'High']);
});

test('a level ticked under Convective Outlooks is not ticked under Flooding, though the Excessive Rainfall Outlook offers it too', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $rainfall = Product::where('code', 'WPC.ERO')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'spc_wpc',
            'product_settings.flooding_and_hydrological' => [alertWizardRow($rainfall, 'nationwide')],
            'product_settings.convective_outlooks' => [alertWizardRow($outlook, 'nationwide')],
            'risk_levels' => ['flooding_and_hydrological' => ['High'], 'convective_outlooks' => ['Moderate']],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();
    expect($rule->riskLevelsFor($outlook->id))->toBe(['Moderate'])
        ->and($rule->riskLevelsFor($rainfall->id))->toBe(['High']);

    livewire(EditAlertRule::class, ['record' => $rule->id])
        ->assertFormSet(['risk_levels' => ['flooding_and_hydrological' => ['High'], 'fire_weather' => [], 'convective_outlooks' => ['Moderate']]]);
});

test('an SPC & WPC rule with no ticks stores an empty list, meaning every level', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'spc_wpc',
            'product_settings.convective_outlooks' => [alertWizardRow($outlook, 'nationwide')],
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
        ->fillForm(['product_settings.convective_outlooks' => [alertWizardRow($outlook, 'nationwide', ['enabled' => false]), alertWizardRow($day2, 'nationwide')]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->refresh()->risk_levels)->toBe([])
        ->and($rule->products->pluck('id')->all())->toBe([$day2->id]);
});

test('a product without risk levels selected beside one with them adds no options to the Convective list', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $day2 = Product::where('code', 'DY2')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'spc_wpc', 'product_settings.convective_outlooks' => [alertWizardRow($outlook, 'nationwide'), alertWizardRow($day2, 'nationwide')]])
        ->assertFormFieldVisible('risk_levels.convective_outlooks')
        ->assertFormFieldExists('risk_levels.convective_outlooks', fn (CheckboxList $field): bool => array_keys($field->getOptions()) === ['Marginal', 'Slight', 'Enhanced', 'Moderate', 'High']);
});

test('the Weather Alerts form never offers a risk checklist, even for a product that has risk level options', function () {
    $tornado = Product::where('code', 'TO.W')->sole();
    $tornado->update(['risk_level_options' => ['Low', 'High']]);

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'weather_alerts', "product_settings.{$tornado->category->value}" => [alertWizardRow($tornado, 'polygon')]])
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

test('choosing Weather Alerts gives every active product a switched-off row at its default mode, with the first saved location', function () {
    Location::factory()->for($this->user)->create(['label' => 'Cabin']);
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $tornadoWatch = Product::where('code', 'TO.A')->sole();
    expect($tornadoWarning->has_polygon)->toBeTrue()->and($tornadoWatch->has_polygon)->toBeFalse();

    $component = livewire(CreateAlertRule::class)->fillForm(['category' => 'weather_alerts']);
    $rows = collect($component->get('data.product_settings'))->flatten(1);

    expect($rows->pluck('product_id')->sort()->values()->all())
        ->toBe(Product::whereIn('family', BuildAlertRuleOptions::WEATHER_FAMILIES)->where('status', 'active')->orderBy('id')->pluck('id')->all())
        ->and($rows->every(fn (array $row): bool => $row['enabled'] === false))->toBeTrue();

    $component->assertFormSet([
        "product_settings.{$tornadoWarning->category->value}.".alertWizardRowIndex($component, $tornadoWarning) => alertWizardRow($tornadoWarning, 'polygon', ['enabled' => false, 'location_ids' => [$this->location->id]]),
        "product_settings.{$tornadoWatch->category->value}.".alertWizardRowIndex($component, $tornadoWatch) => alertWizardRow($tornadoWatch, 'location', ['enabled' => false, 'location_ids' => [$this->location->id]]),
    ]);
});

test('a subscriber with no saved locations gets rows with no location and is told how to add one', function () {
    $this->location->delete();
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    $component = livewire(CreateAlertRule::class)->fillForm(['category' => 'weather_alerts']);

    $component
        ->assertFormSet(["product_settings.{$tornadoWarning->category->value}.".alertWizardRowIndex($component, $tornadoWarning).'.location_ids' => []])
        ->assertSee('No saved locations yet — use the + button to add one.');
});

test('choosing SPC & WPC defaults a risk-level product to risk area, a polygon product to polygon and any other product to nationwide', function () {
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $mesoscale = Product::where('code', 'SPC.MD')->sole();
    $day2 = Product::where('code', 'DY2')->sole();

    $component = livewire(CreateAlertRule::class)->fillForm(['category' => 'spc_wpc']);

    $component->assertFormSet([
        'product_settings.convective_outlooks.'.alertWizardRowIndex($component, $outlook) => alertWizardRow($outlook, 'risk_area', ['enabled' => false, 'location_ids' => [$this->location->id]]),
        "product_settings.{$mesoscale->category->value}.".alertWizardRowIndex($component, $mesoscale) => alertWizardRow($mesoscale, 'polygon', ['enabled' => false, 'location_ids' => [$this->location->id]]),
        'product_settings.convective_outlooks.'.alertWizardRowIndex($component, $day2) => alertWizardRow($day2, 'nationwide', ['enabled' => false]),
    ]);

    expect(collect($component->get('data.product_settings'))->flatten(1)->pluck('product_id')->sort()->values()->all())
        ->toBe(Product::where('family', 'spc_wpc')->where('status', 'active')->orderBy('id')->pluck('id')->all());
});

test('one rule saves a different mode and its own inputs for each product it watches', function () {
    DB::table('cwa_boundaries')->insert([
        'wfo' => 'OKX', 'cwa' => 'OKX', 'city' => 'Upton', 'st' => 'NY', 'citystate' => 'Upton NY',
        'lon' => -72.5, 'lat' => 40.9,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $secondLocation = Location::factory()->for($this->user)->create();
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $tornadoWatch = Product::where('code', 'TO.A')->sole();
    $afd = Product::where('code', 'AFD')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            "product_settings.{$tornadoWarning->category->value}" => [
                alertWizardRow($tornadoWarning, 'polygon', ['location_ids' => [(string) $secondLocation->id], 'wfo' => 'OKX']),
                alertWizardRow($tornadoWatch, 'location', ['location_ids' => [$this->location->id], 'enabled' => false]),
            ],
            "product_settings.{$afd->category->value}" => [alertWizardRow($afd, 'wfo', ['wfo' => 'OKX', 'location_ids' => [$this->location->id]])],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();
    $polygon = $rule->settingsFor($tornadoWarning->id);
    $office = $rule->settingsFor($afd->id);

    expect($rule->products->pluck('id')->all())->toEqualCanonicalizing([$tornadoWarning->id, $afd->id])
        ->and([$polygon->mode, $polygon->wfo, $polygon->locations->pluck('id')->all()])->toBe([MatchingMode::Polygon, null, [$secondLocation->id]])
        ->and([$office->mode, $office->wfo, $office->locations->all()])->toBe([MatchingMode::Wfo, 'OKX', []])
        ->and($rule->only(['matching_mode', 'wfo', 'county_zone_codes']))->toBe(['matching_mode' => null, 'wfo' => null, 'county_zone_codes' => null]);
});

test('editing hydrates each watched product\'s stored settings and leaves every other product switched off at its default', function () {
    DB::table('cwa_boundaries')->insert([
        'wfo' => 'OKX', 'cwa' => 'OKX', 'city' => 'Upton', 'st' => 'NY', 'citystate' => 'Upton NY',
        'lon' => -72.5, 'lat' => 40.9,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $secondLocation = Location::factory()->for($this->user)->create();
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $severe = Product::where('code', 'SV.W')->sole();
    $afd = Product::where('code', 'AFD')->sole();
    $rule = app(SaveAlertRule::class)($this->user, [
        'category' => 'weather_alerts',
        'product_settings' => [
            ['product_id' => $tornadoWarning->id, 'matching_mode' => 'polygon', 'location_ids' => [$secondLocation->id]],
            ['product_id' => $afd->id, 'matching_mode' => 'wfo', 'wfo' => 'OKX'],
        ],
        'channel_ids' => [$this->channel->id],
    ]);

    $component = livewire(EditAlertRule::class, ['record' => $rule->id]);

    $component->assertFormSet([
        "product_settings.{$tornadoWarning->category->value}.".alertWizardRowIndex($component, $tornadoWarning) => alertWizardRow($tornadoWarning, 'polygon', ['location_ids' => [$secondLocation->id]]),
        "product_settings.{$afd->category->value}.".alertWizardRowIndex($component, $afd) => alertWizardRow($afd, 'wfo', ['wfo' => 'OKX']),
        "product_settings.{$severe->category->value}.".alertWizardRowIndex($component, $severe) => alertWizardRow($severe, 'polygon', ['enabled' => false, 'location_ids' => [$this->location->id]]),
    ]);

    $component->call('save')->assertHasNoFormErrors();

    expect($rule->fresh()->products->pluck('id')->all())->toEqualCanonicalizing([$tornadoWarning->id, $afd->id])
        ->and($rule->fresh()->settingsFor($afd->id)->wfo)->toBe('OKX');
});

test('a stored mode the table no longer offers for a product is shown as stored and still saves', function () {
    $mesoscale = Product::where('code', 'SPC.MD')->sole();
    $rule = app(SaveAlertRule::class)($this->user, [
        'category' => 'spc_wpc',
        'product_settings' => [['product_id' => $mesoscale->id, 'matching_mode' => 'risk_area', 'location_ids' => [$this->location->id]]],
        'channel_ids' => [$this->channel->id],
    ]);

    $component = livewire(EditAlertRule::class, ['record' => $rule->id]);
    $table = "product_settings.{$mesoscale->category->value}";
    $index = alertWizardRowIndex($component, $mesoscale);

    $component
        ->assertFormSet(["{$table}.{$index}" => alertWizardRow($mesoscale, 'risk_area', ['location_ids' => [$this->location->id]])])
        ->assertFormFieldExists($table, fn (ProductMatchingTable $field): bool => array_keys($field->modeOptionsFor($field->getState()[$index])) === ['nationwide', 'polygon', 'risk_area']
            && array_keys($field->modeOptionsFor(['product_id' => $mesoscale->id, 'matching_mode' => 'polygon'])) === ['nationwide', 'polygon'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->settingsFor($mesoscale->id)->mode)->toBe(MatchingMode::RiskArea);
});

test('an old-style rule edits as its inherited mode on every watched product and saves into per-product rows', function () {
    $secondLocation = Location::factory()->for($this->user)->create();
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $severe = Product::where('code', 'SV.W')->sole();
    $rule = AlertRule::factory()->for($this->user)->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon', 'location_id' => null]);
    $rule->products()->attach([$tornadoWarning->id, $severe->id]);
    $rule->locations()->attach([$secondLocation->id, $this->location->id]);
    $rule->notificationChannels()->attach($this->channel);

    $component = livewire(EditAlertRule::class, ['record' => $rule->id]);
    $table = "product_settings.{$tornadoWarning->category->value}";

    $component
        ->assertFormSet([
            "{$table}.".alertWizardRowIndex($component, $tornadoWarning) => alertWizardRow($tornadoWarning, 'polygon', ['location_ids' => [$this->location->id, $secondLocation->id]]),
            "{$table}.".alertWizardRowIndex($component, $severe) => alertWizardRow($severe, 'polygon', ['location_ids' => [$this->location->id, $secondLocation->id]]),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $rule = $rule->fresh();

    foreach ([$tornadoWarning, $severe] as $product) {
        $settings = $rule->settingsFor($product->id);

        expect($settings->inherited)->toBeFalse()
            ->and($settings->mode)->toBe(MatchingMode::Polygon)
            ->and($settings->locations->pluck('id')->all())->toBe([$this->location->id, $secondLocation->id]);
    }

    expect($rule->matching_mode)->toBeNull()->and($rule->locations)->toBeEmpty();
});

test('each enabled product must have what its mode needs', function (string $code, string $mode, array $overrides, string $message) {
    $product = Product::where('code', $code)->sole();
    $overrides = collect($overrides)->map(fn ($value) => $value === 'LOCATION' ? [$this->location->id] : $value)->all();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            "product_settings.{$product->category->value}" => [alertWizardRow($product, $mode, $overrides)],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(["product_settings.{$product->category->value}"])
        ->assertSee($message);

    expect(AlertRule::where('user_id', $this->user->id)->exists())->toBeFalse();
})->with([
    'location without a location' => ['TO.A', 'location', [], 'Choose at least one location for Tornado Watch.'],
    'polygon without a location' => ['TO.W', 'polygon', [], 'Choose at least one location for Tornado Warning.'],
    'office without an office' => ['AFD', 'wfo', ['wfo' => ' '], 'Choose a known NWS office for Area Forecast Discussion.'],
    'county/zone without codes' => ['TO.W', 'county_zone', [], 'Choose at least one county or zone for Tornado Warning.'],
    'county/zone with an unknown code' => ['TO.W', 'county_zone', ['county_zone_codes' => ['ZZZ999']], 'Choose known counties or zones for Tornado Warning.'],
    'a mode Weather Alerts does not allow' => ['TO.W', 'risk_area', ['location_ids' => 'LOCATION'], 'Choose a matching mode for Tornado Warning.'],
    'a mode that does not exist' => ['TO.W', 'mixed', [], 'Choose a matching mode for Tornado Warning.'],
]);

test('a switched-off row needs nothing, but at least one product must be switched on', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            "product_settings.{$tornadoWarning->category->value}" => [alertWizardRow($tornadoWarning, 'county_zone', ['enabled' => false])],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['product_settings.tornado_and_severe_thunderstorm'])
        ->assertSee('Select at least one product.')
        ->assertDontSee('Choose at least one county or zone for Tornado Warning.');

    expect(AlertRule::where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('a row may only name a product of its own table', function () {
    $mesoscale = Product::where('code', 'SPC.MD')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            'product_settings.tornado_and_severe_thunderstorm' => [alertWizardRow($mesoscale, 'nationwide')],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['product_settings.tornado_and_severe_thunderstorm']);

    expect(AlertRule::where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('adding a location from a row creates it and selects it on that row only', function () {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $severe = Product::where('code', 'SV.W')->sole();
    $table = "product_settings.{$tornadoWarning->category->value}";

    $component = livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'weather_alerts'])
        ->callFormComponentAction($table, 'addLocation', data: [
            'label' => 'Office',
            'lat' => 38.8976,
            'lng' => -77.0365,
            'address' => '1600 Pennsylvania Ave NW, Washington, DC',
            'timezone' => 'America/New_York',
        ], arguments: ['product' => $tornadoWarning->id])
        ->assertHasNoFormComponentActionErrors();

    $office = Location::where('user_id', $this->user->id)->where('label', 'Office')->sole();

    expect($component->get("data.{$table}.".alertWizardRowIndex($component, $tornadoWarning).'.location_ids'))->toBe([$this->location->id, $office->id])
        ->and($component->get("data.{$table}.".alertWizardRowIndex($component, $severe).'.location_ids'))->toBe([$this->location->id]);
});

test('adding a location from a row works while editing an alert too', function () {
    // Regression: the modal's location form once resolved its `$record` to the alert rule
    // being edited and threw a TypeError in LocationForm's Traccar fields.
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $rule = app(SaveAlertRule::class)($this->user, [
        'category' => 'weather_alerts',
        'product_settings' => [['product_id' => $tornadoWarning->id, 'matching_mode' => 'polygon', 'location_ids' => [$this->location->id]]],
        'channel_ids' => [$this->channel->id],
    ]);
    $table = "product_settings.{$tornadoWarning->category->value}";

    $component = livewire(EditAlertRule::class, ['record' => $rule->id])
        ->mountFormComponentAction($table, 'addLocation', ['product' => $tornadoWarning->id])
        ->fillForm([
            'label' => 'Office',
            'lat' => 38.8976,
            'lng' => -77.0365,
            'address' => '1600 Pennsylvania Ave NW, Washington, DC',
            'timezone' => 'America/New_York',
        ], 'mountedActionSchema0')
        ->callMountedFormComponentAction()
        ->assertHasNoFormComponentActionErrors();

    $office = Location::where('user_id', $this->user->id)->where('label', 'Office')->sole();

    $component->call('save')->assertHasNoFormErrors();

    expect($rule->fresh()->settingsFor($tornadoWarning->id)->locations->pluck('id')->all())->toBe([$this->location->id, $office->id]);
});

test('a product with an example shows an icon button for it, and a product without one does not', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $severe = Product::where('code', 'SV.W')->sole();
    $tornadoWarning->update(['example' => 'BULLETIN - IMMEDIATE BROADCAST REQUESTED']);
    $severe->update(['example' => "  \n "]);

    $component = livewire(CreateAlertRule::class)->fillForm(['category' => 'weather_alerts']);
    $html = $component->html();

    expect($html)->toContain('aria-label="Show example of '.$tornadoWarning->name.'"')
        ->and($html)->not->toContain('aria-label="Show example of '.$severe->name.'"');
});

test('a product example is not in the page or the component state until its modal is opened', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $tornadoWarning->update(['example' => 'BULLETIN - IMMEDIATE BROADCAST REQUESTED zebra-crossing-marker']);
    $table = "product_settings.{$tornadoWarning->category->value}";

    $component = livewire(CreateAlertRule::class)->fillForm(['category' => 'weather_alerts']);

    expect($component->html())->not->toContain('zebra-crossing-marker')
        ->and(json_encode($component->snapshot))->not->toContain('zebra-crossing-marker');

    $component->mountFormComponentAction($table, 'showExample', ['product' => $tornadoWarning->id])
        ->assertFormComponentActionMounted($table, 'showExample')
        ->assertMountedActionModalSee(['zebra-crossing-marker', $tornadoWarning->name, 'Close']);
});

test('the example modal escapes the text and has no submit button', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $tornadoWarning->update(['example' => '<script>alert("x")</script> WWUS55']);
    $table = "product_settings.{$tornadoWarning->category->value}";

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'weather_alerts'])
        ->mountFormComponentAction($table, 'showExample', ['product' => $tornadoWarning->id])
        ->assertMountedActionModalSeeHtml('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; WWUS55')
        ->assertMountedActionModalDontSeeHtml('<script>alert("x")</script>')
        ->assertMountedActionModalDontSee('Submit');
});

test('the example modal refuses a product that is not in its table or has no example', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $table = "product_settings.{$tornadoWarning->category->value}";
    $otherCategory = Product::whereNotNull('category')->where('category', '!=', $tornadoWarning->category)->where('family', 'warning')->firstOrFail();
    $otherCategory->update(['example' => 'secret-other-table-example']);
    $blank = Product::where('code', 'SV.W')->sole();
    $blank->update(['example' => null]);
    $spc = Product::where('family', 'spc_wpc')->firstOrFail();
    $spc->update(['example' => 'secret-spc-example']);

    foreach ([$otherCategory->id, $blank->id, $spc->id, 0, 999999] as $productId) {
        livewire(CreateAlertRule::class)
            ->fillForm(['category' => 'weather_alerts'])
            ->mountFormComponentAction($table, 'showExample', ['product' => $productId])
            ->assertFormComponentActionNotMounted($table, 'showExample')
            ->assertDontSee('secret-other-table-example')
            ->assertDontSee('secret-spc-example');
    }
});

test('the add-a-location action is hidden once a Free subscriber is at their location limit', function () {
    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'weather_alerts'])
        ->assertFormComponentActionHidden('product_settings.tornado_and_severe_thunderstorm', 'addLocation');
});

test('the WFO input offers every office from one list per form', function () {
    DB::table('cwa_boundaries')->insert([
        'wfo' => 'OKX', 'cwa' => 'OKX', 'city' => 'Upton', 'st' => 'NY', 'citystate' => 'Upton NY',
        'lon' => -72.5, 'lat' => 40.9,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $html = livewire(CreateAlertRule::class)->fillForm(['category' => 'weather_alerts'])->html();

    expect(substr_count($html, '<datalist'))->toBe(1)
        ->and($html)->toContain('<option value="OKX">OKX — Upton, NY</option>');
});

test('a failed save leaves the stored rule unchanged', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $rule = AlertRule::factory()->for($this->user)->create(['category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => null, 'name' => 'Before']);
    $rule->products()->attach($tornadoWarning);
    $rule->locations()->attach($this->location);
    $rule->notificationChannels()->attach($this->channel);

    app()->bind(SaveAlertRule::class, fn () => new class extends SaveAlertRule
    {
        public function syncRelations(AlertRule $rule, array $data): void
        {
            throw new RuntimeException('The pivot sync failed.');
        }
    });

    expect(fn () => livewire(EditAlertRule::class, ['record' => $rule->id])->set('data.name', 'After')->call('save'))
        ->toThrow(RuntimeException::class, 'The pivot sync failed.');

    $rule = $rule->fresh();

    expect($rule->name)->toBe('Before')
        ->and($rule->matching_mode)->toBe(MatchingMode::Location)
        ->and($rule->locations->pluck('id')->all())->toBe([$this->location->id])
        ->and($rule->settingsFor($tornadoWarning->id)->inherited)->toBeTrue();

    expect(fn () => livewire(CreateAlertRule::class)->fillForm([
        'category' => 'weather_alerts',
        "product_settings.{$tornadoWarning->category->value}" => [alertWizardRow($tornadoWarning, 'nationwide')],
        'channel_ids' => [$this->channel->id],
    ])->call('create'))->toThrow(RuntimeException::class);

    expect(AlertRule::where('user_id', $this->user->id)->count())->toBe(1);
});

test('rows under a key that is not one of the form\'s tables are never saved', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $mesoscale = Product::where('code', 'SPC.MD')->sole();
    $othersLocation = Location::factory()->for(User::factory())->create();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            "product_settings.{$tornadoWarning->category->value}" => [alertWizardRow($tornadoWarning, 'polygon', ['location_ids' => [$this->location->id]])],
            'product_settings.bogus' => [alertWizardRow($mesoscale, 'polygon', ['location_ids' => [$othersLocation->id]])],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->products->pluck('id')->all())->toBe([$tornadoWarning->id])
        ->and(DB::table('alert_rule_product_location')->where('location_id', $othersLocation->id)->exists())->toBeFalse();
});

test('a product_settings state that is a bare list of rows is not a product choice', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $othersLocation = Location::factory()->for(User::factory())->create();

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => 'weather_alerts', 'channel_ids' => [$this->channel->id]])
        ->set('data.product_settings', [alertWizardRow($tornadoWarning, 'polygon', ['location_ids' => [$othersLocation->id]])])
        ->call('create')
        ->assertHasFormErrors(['product_settings.tornado_and_severe_thunderstorm']);

    expect(AlertRule::where('user_id', $this->user->id)->exists())->toBeFalse()
        ->and(DB::table('alert_rule_product_location')->where('location_id', $othersLocation->id)->exists())->toBeFalse();
});

test('a product listed twice in its table is refused', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            "product_settings.{$tornadoWarning->category->value}" => [
                alertWizardRow($tornadoWarning, 'nationwide'),
                alertWizardRow($tornadoWarning, 'polygon', ['location_ids' => [$this->location->id], 'enabled' => false]),
            ],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(["product_settings.{$tornadoWarning->category->value}"])
        ->assertSee('Tornado Warning is listed more than once.');

    expect(AlertRule::where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('editing cannot pull a retired product into the table through the form state', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $retired = Product::where('code', 'SV.W')->sole();
    $retired->update(['status' => ProductStatus::Draft]);
    $rule = app(SaveAlertRule::class)($this->user, [
        'category' => 'weather_alerts',
        'product_settings' => [['product_id' => $tornadoWarning->id, 'matching_mode' => 'nationwide']],
        'channel_ids' => [$this->channel->id],
    ]);
    $table = "product_settings.{$tornadoWarning->category->value}";

    $component = livewire(EditAlertRule::class, ['record' => $rule->id]);
    $rows = $component->get("data.{$table}");

    $component
        ->set("data.{$table}", [...$rows, alertWizardRow($retired, 'nationwide')])
        ->call('save')
        ->assertHasFormErrors([$table]);

    expect($rule->fresh()->products->pluck('id')->all())->toBe([$tornadoWarning->id]);
});

test('a subscriber with no saved locations cannot save a switched-on location-based row', function () {
    $this->location->delete();
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            "product_settings.{$tornadoWarning->category->value}" => [alertWizardRow($tornadoWarning, 'polygon')],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(["product_settings.{$tornadoWarning->category->value}"])
        ->assertSee('Choose at least one location for Tornado Warning.');

    expect(AlertRule::where('user_id', $this->user->id)->exists())->toBeFalse();
});

test('a location added from a row is offered to every row without a reload', function () {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    $table = "product_settings.{$tornadoWarning->category->value}";
    // The table's Alpine scope holds the location list; a new wire:key makes the morph start a new one.
    $scopeKey = fn (string $html): ?string => preg_match('/wire:key="data\.'.preg_quote($table, '/').'\.locations\.([0-9a-f]+)"/', $html, $match) ? $match[1] : null;

    $component = livewire(CreateAlertRule::class)->fillForm(['category' => 'weather_alerts'])->assertDontSee('Lake house');
    $keyBefore = $scopeKey($component->html());

    $component
        ->callFormComponentAction($table, 'addLocation', data: [
            'label' => 'Lake house',
            'lat' => 38.8976,
            'lng' => -77.0365,
            'address' => '1600 Pennsylvania Ave NW, Washington, DC',
            'timezone' => 'America/New_York',
        ], arguments: ['product' => $tornadoWarning->id])
        ->assertHasNoFormComponentActionErrors()
        ->assertSee('Lake house');

    expect($keyBefore)->not->toBeNull()
        ->and($scopeKey($component->html()))->not->toBeNull()->not->toBe($keyBefore);
});

test('every product table has its own search box and Enable all switch, both handled in the browser', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    $component = livewire(CreateAlertRule::class)->fillForm(['category' => 'weather_alerts']);
    $html = $component->html();
    $tableKeys = array_keys($component->get('data.product_settings'));

    expect($tableKeys)->not->toBeEmpty()
        ->and(substr_count($html, 'data-category-search='))->toBe(count($tableKeys))
        ->and(substr_count($html, 'data-enable-all='))->toBe(count($tableKeys))
        ->and($html)->toContain('data-category-search="tornado_and_severe_thunderstorm"')
        ->and($html)->toContain('data-enable-all="tornado_and_severe_thunderstorm"')
        ->and($html)->toContain('x-model="search"')
        ->and($html)->toContain('x-on:click="toggleAll()"')
        ->and($html)->toContain('Enable all')
        // Neither makes a request: the search is not a Livewire property and the switch is not wired to a server method.
        ->and($html)->not->toContain('wire:model="search"');

    preg_match_all('/<input[^>]*data-category-search[^>]*>/s', $html, $searchInputs);
    preg_match_all('/<button[^>]*data-enable-all[^>]*>/s', $html, $switches);

    expect(implode($searchInputs[0]).implode($switches[0]))->not->toContain('wire:');

    $searchText = e(mb_strtolower($tornadoWarning->name.' '.$tornadoWarning->description));

    expect($html)->toContain('data-search="'.$searchText.'"')
        ->and($html)->toContain('aria-label="Choose zones for '.e($tornadoWarning->name).'"')
        // A morph can replace the table root, so the no-match check must not assume $root exists.
        ->and($html)->toContain('this.$root?.querySelectorAll(');
});

test('enabling every row of one category saves exactly that category\'s products and leaves each row\'s mode and locations alone', function () {
    $secondLocation = Location::factory()->for($this->user)->create();
    $category = Product::where('code', 'TO.W')->sole()->category;
    $inCategory = Product::whereIn('family', BuildAlertRuleOptions::WEATHER_FAMILIES)->where('status', 'active')->where('category', $category->value)->orderBy('id')->get();

    $component = livewire(CreateAlertRule::class)->fillForm(['category' => 'weather_alerts']);
    $rows = collect($component->get("data.product_settings.{$category->value}"));
    $rows = $rows->map(fn (array $row): array => $row['matching_mode'] === 'location' ? [...$row, 'location_ids' => [$secondLocation->id]] : $row);

    $component
        ->fillForm([
            "product_settings.{$category->value}" => $rows->map(fn (array $row): array => [...$row, 'enabled' => true])->all(),
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($inCategory->count())->toBeGreaterThan(1)
        ->and($rule->products->pluck('id')->all())->toEqualCanonicalizing($inCategory->pluck('id')->all());

    foreach ($rows as $row) {
        $settings = $rule->settingsFor($row['product_id']);

        expect($settings->mode->value)->toBe($row['matching_mode'])
            ->and($settings->locations->pluck('id')->all())->toEqualCanonicalizing($row['location_ids']);
    }
});

test('Enable all on one category while editing keeps the products and settings of the others, and Disable all clears only its own', function () {
    DB::table('cwa_boundaries')->insert([
        'wfo' => 'OKX', 'cwa' => 'OKX', 'city' => 'Upton', 'st' => 'NY', 'citystate' => 'Upton NY',
        'lon' => -72.5, 'lat' => 40.9,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-73 40, -72 40, -72 41, -73 41, -73 40))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $afd = Product::where('code', 'AFD')->sole();
    $category = $tornadoWarning->category;
    $rule = app(SaveAlertRule::class)($this->user, [
        'category' => 'weather_alerts',
        'product_settings' => [
            ['product_id' => $tornadoWarning->id, 'matching_mode' => 'polygon', 'location_ids' => [$this->location->id]],
            ['product_id' => $afd->id, 'matching_mode' => 'wfo', 'wfo' => 'OKX'],
        ],
        'channel_ids' => [$this->channel->id],
    ]);
    $inCategory = Product::whereIn('family', BuildAlertRuleOptions::WEATHER_FAMILIES)->where('status', 'active')->where('category', $category->value)->pluck('id')->all();

    $component = livewire(EditAlertRule::class, ['record' => $rule->id]);
    $rows = collect($component->get("data.product_settings.{$category->value}"));

    $component
        ->fillForm(["product_settings.{$category->value}" => $rows->map(fn (array $row): array => [...$row, 'enabled' => true])->all()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->products->pluck('id')->all())->toEqualCanonicalizing([...$inCategory, $afd->id])
        ->and($rule->fresh()->settingsFor($tornadoWarning->id)->mode)->toBe(MatchingMode::Polygon)
        ->and($rule->fresh()->settingsFor($afd->id)->wfo)->toBe('OKX');

    $component = livewire(EditAlertRule::class, ['record' => $rule->id]);
    $rows = collect($component->get("data.product_settings.{$category->value}"));

    $component
        ->fillForm(["product_settings.{$category->value}" => $rows->map(fn (array $row): array => [...$row, 'enabled' => false])->all()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->products->pluck('id')->all())->toBe([$afd->id])
        ->and($rule->fresh()->settingsFor($afd->id)->wfo)->toBe('OKX');
});

test('switching every product off leaves nothing to save', function () {
    $category = Product::where('code', 'TO.W')->sole()->category;

    $component = livewire(CreateAlertRule::class)->fillForm(['category' => 'weather_alerts']);
    $rows = collect($component->get("data.product_settings.{$category->value}"));

    $component
        ->fillForm([
            "product_settings.{$category->value}" => $rows->map(fn (array $row): array => [...$row, 'enabled' => false])->all(),
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['product_settings.tornado_and_severe_thunderstorm' => 'Select at least one product.']);

    expect(AlertRule::where('user_id', $this->user->id)->exists())->toBeFalse();
});

/**
 * The server-rendered markup of one product's row in the alert wizard's product table, with any
 * Alpine `<template>` content (which the browser never renders until Alpine adds it) removed.
 */
function alertRuleResourceRenderedRow(string $html, Product $product): string
{
    expect(preg_match('/<tr[^>]*data-product-id="'.$product->id.'".*?<\/tr>/s', $html, $match))->toBe(1);

    return preg_replace('/<template\b.*?<\/template>/s', '', $match[0]);
}

test('a subject typed on one switched-on product is stored on that product only', function () {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $tornadoWatch = Product::where('code', 'TO.A')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            "product_settings.{$tornadoWarning->category->value}" => [
                alertWizardRow($tornadoWarning, 'nationwide', ['custom_subject_template' => '  {product} for {location} {until}  ']),
                alertWizardRow($tornadoWatch, 'nationwide'),
            ],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = AlertRule::where('user_id', $this->user->id)->sole();

    expect($rule->products->firstWhere('id', $tornadoWarning->id)->pivot->custom_subject_template)->toBe('{product} for {location} {until}')
        ->and($rule->products->firstWhere('id', $tornadoWatch->id)->pivot->custom_subject_template)->toBeNull();
});

test('editing shows each product\'s stored subject in its row, and an empty one for a product without', function () {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $tornadoWatch = Product::where('code', 'TO.A')->sole();
    $rule = app(SaveAlertRule::class)($this->user, [
        'category' => 'weather_alerts',
        'product_settings' => [
            ['product_id' => $tornadoWarning->id, 'matching_mode' => 'nationwide', 'custom_subject_template' => '{product} now'],
            ['product_id' => $tornadoWatch->id, 'matching_mode' => 'nationwide'],
        ],
        'channel_ids' => [$this->channel->id],
    ]);

    $component = livewire(EditAlertRule::class, ['record' => $rule->id]);
    $category = $tornadoWarning->category->value;

    $component->assertFormSet([
        "product_settings.{$category}.".alertWizardRowIndex($component, $tornadoWarning).'.custom_subject_template' => '{product} now',
        "product_settings.{$category}.".alertWizardRowIndex($component, $tornadoWatch).'.custom_subject_template' => '',
    ]);
});

test('a switched-off row\'s subject is not saved, and clearing a subject saves none', function () {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $tornadoWatch = Product::where('code', 'TO.A')->sole();
    $rule = app(SaveAlertRule::class)($this->user, [
        'category' => 'weather_alerts',
        'product_settings' => [['product_id' => $tornadoWarning->id, 'matching_mode' => 'nationwide', 'custom_subject_template' => '{product} now']],
        'channel_ids' => [$this->channel->id],
    ]);

    $component = livewire(EditAlertRule::class, ['record' => $rule->id]);
    $category = $tornadoWarning->category->value;
    $watchIndex = alertWizardRowIndex($component, $tornadoWatch);

    $component
        ->set("data.product_settings.{$category}.".alertWizardRowIndex($component, $tornadoWarning).'.custom_subject_template', '   ')
        ->set("data.product_settings.{$category}.{$watchIndex}.custom_subject_template", 'Never saved')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->products->pluck('pivot.custom_subject_template', 'id')->all())->toBe([$tornadoWarning->id => null]);
});

test('a product\'s subject may be 255 characters but not 256', function (int $length, bool $passes) {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    $component = livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            "product_settings.{$tornadoWarning->category->value}" => [alertWizardRow($tornadoWarning, 'nationwide', ['custom_subject_template' => str_repeat('a', $length)])],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create');

    if ($passes) {
        $component->assertHasNoFormErrors();
        expect(AlertRule::where('user_id', $this->user->id)->sole()->customSubjectFor($tornadoWarning->id))->toBe(str_repeat('a', 255));
    } else {
        $component->assertHasFormErrors(["product_settings.{$tornadoWarning->category->value}"])
            ->assertSee('The custom subject for Tornado Warning field must not be greater than 255 characters.');
        expect(AlertRule::where('user_id', $this->user->id)->exists())->toBeFalse();
    }
})->with([
    '255 characters' => [255, true],
    '256 characters' => [256, false],
]);

test('a product\'s subject must be text', function () {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();
    $tornadoWarning = Product::where('code', 'TO.W')->sole();

    livewire(CreateAlertRule::class)
        ->fillForm([
            'category' => 'weather_alerts',
            "product_settings.{$tornadoWarning->category->value}" => [alertWizardRow($tornadoWarning, 'nationwide', ['custom_subject_template' => ['{product}']])],
            'channel_ids' => [$this->channel->id],
        ])
        ->call('create')
        ->assertHasFormErrors(["product_settings.{$tornadoWarning->category->value}"])
        ->assertSee('The custom subject for Tornado Warning field must be a string.');
});

test('a paid subscriber\'s switched-on row offers a subject, and a switched-off row renders no subject input', function () {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $tornadoWatch = Product::where('code', 'TO.A')->sole();

    $component = livewire(CreateAlertRule::class)->fillForm([
        'category' => 'weather_alerts',
        "product_settings.{$tornadoWarning->category->value}" => [
            alertWizardRow($tornadoWarning, 'nationwide', ['custom_subject_template' => '{product} now']),
            alertWizardRow($tornadoWatch, 'nationwide', ['enabled' => false]),
        ],
    ]);
    $html = $component->html();
    $onRow = alertRuleResourceRenderedRow($html, $tornadoWarning);
    $offRow = alertRuleResourceRenderedRow($html, $tornadoWatch);

    expect($html)->toContain('Uses the default subject')
        ->and($onRow)->toContain('{product} now')
        ->and($onRow)->toContain('Edit')
        ->and($html)->toContain('wire:model="data.product_settings.'.$tornadoWarning->category->value.'.0.custom_subject_template"')
        ->and($html)->toContain(e(SubjectPlaceholders::helperText($tornadoWarning)))
        ->and($offRow)->toContain('Custom subject')
        ->and($onRow.$offRow)->not->toContain('custom_subject_template"');
});

test('a Free subscriber\'s product table has no subject control, and a save with one raises no error and keeps the stored subject', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $tornadoWatch = Product::where('code', 'TO.A')->sole();
    $rule = app(SaveAlertRule::class)($this->user, [
        'category' => 'weather_alerts',
        'product_settings' => [['product_id' => $tornadoWarning->id, 'matching_mode' => 'nationwide', 'custom_subject_template' => 'From before the downgrade']],
        'channel_ids' => [$this->channel->id],
    ]);

    $component = livewire(EditAlertRule::class, ['record' => $rule->id]);
    $category = $tornadoWarning->category->value;
    $watchIndex = alertWizardRowIndex($component, $tornadoWatch);

    expect($component->html())->not->toContain('Uses the default subject')
        ->and($component->html())->not->toMatch('/product_settings\.\w+\.\d+\.custom_subject_template"/');

    $component
        ->set("data.product_settings.{$category}.{$watchIndex}.enabled", true)
        ->set("data.product_settings.{$category}.{$watchIndex}.matching_mode", 'nationwide')
        ->set("data.product_settings.{$category}.{$watchIndex}.custom_subject_template", str_repeat('a', 300))
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->products->pluck('pivot.custom_subject_template', 'id')->all())
        ->toEqual([$tornadoWarning->id => 'From before the downgrade', $tornadoWatch->id => null]);
});

test('the rule-wide subject is the default for a product rule and keeps its old label elsewhere', function (string $category, string $label, string $otherLabel) {
    $this->user->forceFill(['tier_override' => Tier::Plus])->save();

    livewire(CreateAlertRule::class)
        ->fillForm(['category' => $category])
        ->assertSee($label)
        ->assertDontSee($otherLabel);
})->with([
    'weather alerts' => ['weather_alerts', 'Default subject line (optional)', 'Custom subject line (optional)'],
    'spc & wpc' => ['spc_wpc', 'Default subject line (optional)', 'Custom subject line (optional)'],
    'threshold' => ['threshold', 'Custom subject line (optional)', 'Default subject line (optional)'],
]);
