<?php

use App\Actions\Alerts\GetActiveNowAtMyLocations;
use App\Actions\Weather\GetActiveAlertsForLocation;
use App\Filament\Dashboard\Resources\AlertRules\Widgets\ActiveNowWidget;
use App\Models\ActiveWwa;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-03 15:00:00');
    $this->user = User::factory()->create();
    $this->home = Location::factory()->for($this->user)->create([
        'label' => 'Home', 'lat' => 40.0, 'lng' => -105.0, 'timezone' => 'America/Denver',
        'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** An active warning covering the given UGC zone (polygon-less), with a matching ingested product. */
function activeNowWarning(string $name = 'Tornado Warning', string $family = 'warning', string $zone = 'COZ040', array $attributes = []): ActiveWwa
{
    $vtec = ['vtec_office' => 'KBOU', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W', 'vtec_year' => 2026];

    return ActiveWwa::factory()->create([
        'product_id' => Product::factory()->create(['name' => $name, 'family' => $family])->id,
        'ugc_codes' => [$zone],
        'expires' => now()->addHour(),
        ...$vtec,
        ...$attributes,
    ]);
}

/** Records a delivery of the warning's event to the user. */
function activeNowDelivery(User $user, ActiveWwa $wwa, string $status, ?AlertRule $rule = null, string|Carbon|null $when = null): DeliveryLog
{
    // Quietly: the observer would queue ProcessIngestedProduct and re-sync (overwrite) the active_wwas row.
    $ingested = IngestedProduct::factory()->createQuietly([
        'vtec_office' => $wwa->vtec_office, 'vtec_phenomenon' => $wwa->vtec_phenomenon, 'vtec_significance' => $wwa->vtec_significance,
        'vtec_event_number' => $wwa->vtec_event_number, 'vtec_year' => $wwa->vtec_year, 'is_test' => false,
    ]);

    return DeliveryLog::factory()->for($user)->create([
        'ingested_product_id' => $ingested->id, 'alert_rule_id' => $rule?->id, 'status' => $status, 'dispatched_at' => $when ?? now()->subMinutes(30),
    ]);
}

function activeNowRule(User $user, string $name): AlertRule
{
    return AlertRule::factory()->for($user)->create(['name' => $name, 'category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => null]);
}

test('an active warning at a location is listed with the location and its expiry in that location\'s timezone', function () {
    activeNowWarning('Tornado Warning');

    $result = (new GetActiveNowAtMyLocations)($this->user);

    expect($result['has_locations'])->toBeTrue()->and($result['events'])->toHaveCount(1)
        ->and($result['events'][0])->toMatchArray(['event' => 'Tornado Warning', 'family' => 'warning', 'locations' => ['Home'], 'status' => 'none'])
        ->and($result['events'][0]['expires'])->toBe('2026-10-03T10:00:00-06:00');
});

test('one warning covering two locations is a single event naming both', function () {
    Location::factory()->for($this->user)->create(['label' => 'Cabin', 'lat' => 40.1, 'lng' => -105.1, 'timezone' => 'America/Denver', 'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014']);
    activeNowWarning();

    $events = (new GetActiveNowAtMyLocations)($this->user)['events'];

    expect($events)->toHaveCount(1)->and($events[0]['locations'])->toBe(['Cabin', 'Home']);
});

test('status is alerted when a delivery succeeded, naming the alert and time', function () {
    $wwa = activeNowWarning();
    activeNowDelivery($this->user, $wwa, 'success', activeNowRule($this->user, 'Storm warnings'), now()->subMinutes(45));

    $event = (new GetActiveNowAtMyLocations)($this->user)['events'][0];

    expect($event['status'])->toBe('alerted')->and($event['alerted_via'])->toBe(['Storm warnings'])
        ->and($event['alerted_at'])->toBe('2026-10-03T08:15:00-06:00');
});

test('status precedence is alerted over held over failed over none', function () {
    $held = activeNowWarning('Held one');
    activeNowDelivery($this->user, $held, 'suppressed');
    $failed = activeNowWarning('Failed one');
    activeNowDelivery($this->user, $failed, 'failure');
    $mixed = activeNowWarning('Mixed one');
    activeNowDelivery($this->user, $mixed, 'failure');
    activeNowDelivery($this->user, $mixed, 'suppressed');
    activeNowDelivery($this->user, $mixed, 'success', activeNowRule($this->user, 'Rule'));
    activeNowWarning('Silent one');

    $byEvent = collect((new GetActiveNowAtMyLocations)($this->user)['events'])->pluck('status', 'event');

    expect($byEvent['Held one'])->toBe('held')->and($byEvent['Failed one'])->toBe('failed')
        ->and($byEvent['Mixed one'])->toBe('alerted')->and($byEvent['Silent one'])->toBe('none');
});

test('suppressed and failed deliveries with no success read as held; failed alone reads as failed', function () {
    $both = activeNowWarning('Both');
    activeNowDelivery($this->user, $both, 'failure');
    activeNowDelivery($this->user, $both, 'suppressed');
    $failedOnly = activeNowWarning('Failed only');
    activeNowDelivery($this->user, $failedOnly, 'failure');

    $byEvent = collect((new GetActiveNowAtMyLocations)($this->user)['events'])->pluck('status', 'event');

    expect($byEvent['Both'])->toBe('held')->and($byEvent['Failed only'])->toBe('failed');
});

test('a delivery from before the event first existed is not coverage, and a skipped row never is', function () {
    $wwa = activeNowWarning(attributes: ['created_at' => now()->subHours(2)]);
    activeNowDelivery($this->user, $wwa, 'success', when: now()->subDays(2));
    activeNowDelivery($this->user, $wwa, 'skipped', when: now()->subMinutes(30));

    expect((new GetActiveNowAtMyLocations)($this->user)['events'][0]['status'])->toBe('none');

    activeNowDelivery($this->user, $wwa, 'success', when: now()->subDay()->subHours(1)); // within a day before the first ingest: still counts

    Cache::flush();
    expect((new GetActiveNowAtMyLocations)($this->user)['events'][0]['status'])->toBe('alerted');
});

test('only this subscriber\'s deliveries count as coverage', function () {
    $wwa = activeNowWarning();
    activeNowDelivery(User::factory()->create(), $wwa, 'success');

    expect((new GetActiveNowAtMyLocations)($this->user)['events'][0]['status'])->toBe('none');
});

test('a delivery for a different event does not cover this one', function () {
    $wwa = activeNowWarning('Tornado Warning', attributes: ['vtec_event_number' => '0001']);
    $other = activeNowWarning('Other event', attributes: ['vtec_event_number' => '0002', 'ugc_codes' => ['COZ999']]);
    activeNowDelivery($this->user, $other, 'success');

    expect((new GetActiveNowAtMyLocations)($this->user)['events'][0]['status'])->toBe('none');
});

test('test warnings are ignored', function () {
    activeNowWarning('Test warning', attributes: ['is_test' => true]);

    expect((new GetActiveNowAtMyLocations)($this->user)['events'])->toBe([]);
});

test('locations without a position are filtered out by the query, so they neither match nor count toward the cap', function () {
    $loner = User::factory()->create();
    Location::factory()->mobile()->count(26)->for($loner)->create(['forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014']);
    activeNowWarning();

    $result = (new GetActiveNowAtMyLocations)($loner);

    expect($result)->toBe(['events' => [], 'more' => 0, 'has_locations' => true, 'has_position' => false, 'capped' => false]);

    Location::factory()->for($loner)->create(['label' => 'Fixed', 'lat' => 40.0, 'lng' => -105.0, 'forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014']);
    Cache::flush();
    $result = (new GetActiveNowAtMyLocations)($loner);

    expect($result['capped'])->toBeFalse()->and($result['has_position'])->toBeTrue()
        ->and($result['events'][0]['locations'])->toBe(['Fixed']);
});

test('events are sorted by family then expiry and capped at 6 with a count of the rest', function () {
    foreach (range(1, 5) as $i) {
        activeNowWarning("Advisory {$i}", 'advisory', attributes: ['expires' => now()->addHours($i)]);
    }
    activeNowWarning('Late warning', 'warning', attributes: ['expires' => now()->addHours(9)]);
    activeNowWarning('Early warning', 'warning', attributes: ['expires' => now()->addHours(2)]);
    activeNowWarning('A watch', 'watch');

    $result = (new GetActiveNowAtMyLocations)($this->user);

    expect(collect($result['events'])->pluck('event')->all())->toBe(['Early warning', 'Late warning', 'A watch', 'Advisory 1', 'Advisory 2', 'Advisory 3'])
        ->and($result['more'])->toBe(2);
});

test('the result is cached for 60 seconds per subscriber', function () {
    activeNowWarning('First');
    (new GetActiveNowAtMyLocations)($this->user);

    activeNowWarning('Second');
    expect(collect((new GetActiveNowAtMyLocations)($this->user)['events'])->pluck('event')->all())->toBe(['First']);

    Carbon::setTestNow(now()->addSeconds(61));
    expect(collect((new GetActiveNowAtMyLocations)($this->user)['events'])->pluck('event')->sort()->values()->all())->toBe(['First', 'Second']);
});

test('only the first 25 locations with a position are looked at, and the result says so', function () {
    // Home + 24 others = 25 looked at; the 26th (created last) is past the cap and is the only one in the warning's zone.
    Location::factory()->for($this->user)->count(24)->create(['lat' => 41.0, 'lng' => -100.0, 'forecast_zone_ugc' => 'COZ777', 'county_ugc' => 'COC777']);
    Location::factory()->for($this->user)->create(['label' => 'Twenty-sixth', 'lat' => 42.0, 'lng' => -101.0, 'forecast_zone_ugc' => 'COZ888', 'county_ugc' => 'COC888']);
    activeNowWarning('Beyond the cap', zone: 'COZ888');

    $result = (new GetActiveNowAtMyLocations)($this->user);

    expect($result['capped'])->toBeTrue()->and($result['events'])->toBe([]);

    Cache::flush();
    Location::query()->where('label', 'Twenty-sixth')->delete();

    $result = (new GetActiveNowAtMyLocations)($this->user);

    expect($result['capped'])->toBeFalse();
});

test('a subscriber with no locations says so', function () {
    $loner = User::factory()->create();

    expect((new GetActiveNowAtMyLocations)($loner))->toBe(['events' => [], 'more' => 0, 'has_locations' => false, 'has_position' => false, 'capped' => false]);
});

test('extracting activeFor() left GetActiveAlertsForLocation\'s output unchanged', function () {
    activeNowWarning('Tornado Warning', attributes: ['headline' => 'Tornado Warning issued']);

    $alerts = (new GetActiveAlertsForLocation)($this->home);

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0])->toBe([
            'event' => 'Tornado Warning', 'headline' => 'Tornado Warning issued',
            'expires' => '2026-10-03T10:00:00-06:00', 'family' => 'warning',
        ]);
    expect((new GetActiveAlertsForLocation)->activeFor($this->home)->pluck('id')->all())->toHaveCount(1);
});

test('resolving a location\'s zones while reading is quiet: it works in maintenance mode and writes no activity row', function () {
    foreach (['public_zones' => ['zone' => '040', 'state_zone' => 'CO040', 'name' => 'Test Zone'], 'fire_zones' => ['zone' => '040', 'state_zone' => 'CO040', 'name' => 'Test Fire'], 'counties' => ['countyname' => 'Test County', 'fips' => '08014']] as $table => $attributes) {
        DB::table($table)->insert([
            'state' => 'CO', 'cwa' => 'BOU', ...$attributes, 'lon' => 0, 'lat' => 0,
            'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-106 39, -104 39, -104 41, -106 41, -106 39))')), 4269)"),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $resolving = Location::factory()->for($this->user)->create(['label' => 'Fresh', 'lat' => 40.0, 'lng' => -105.0, 'timezone' => 'America/Denver']);
    $resolving->forceFill(['forecast_zone_ugc' => null, 'county_ugc' => null, 'fire_weather_zone_ugc' => null])->saveQuietly();
    activeNowWarning('Tornado Warning', zone: 'COZ040');
    $this->actingAs($this->user);
    $settings = app(GeneralSettings::class);
    $settings->maintenance_mode = true;
    $settings->save();
    Activity::query()->delete();

    $lookup = (new GetActiveAlertsForLocation)($resolving);

    expect($lookup)->toHaveCount(1)
        ->and($resolving->fresh()->forecast_zone_ugc)->toBe('COZ040')
        ->and($resolving->fresh()->county_ugc)->toBe('COC014')
        ->and(Activity::query()->get()->toArray())->toBe([]);

    $resolving->forceFill(['forecast_zone_ugc' => null, 'county_ugc' => null])->saveQuietly();
    Cache::flush();

    livewire(ActiveNowWidget::class)->assertSee('Tornado Warning');
    expect($resolving->fresh()->forecast_zone_ugc)->toBe('COZ040')->and(Activity::query()->count())->toBe(0);
});

test('an active event the product catalog does not know is named from its VTEC codes, with the family their significance gives', function () {
    activeNowWarning('Tornado Warning');
    ActiveWwa::factory()->create([
        'product_id' => null, 'ugc_codes' => ['COZ040'], 'expires' => now()->addHours(2),
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'SS', 'vtec_significance' => 'A', 'vtec_year' => 2026,
    ]);
    ActiveWwa::factory()->create([
        'product_id' => null, 'ugc_codes' => ['COZ040'], 'expires' => now()->addHours(3),
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'RP', 'vtec_significance' => 'S', 'vtec_year' => 2026,
    ]);

    $events = (new GetActiveNowAtMyLocations)($this->user)['events'];

    expect(array_column($events, 'event'))->toBe(['Tornado Warning', 'Storm Surge Watch', 'Rip Current Statement'])
        ->and(array_column($events, 'family'))->toBe(['warning', 'watch', 'statement']);
});

test('an active event whose codes are unknown still reads Weather alert', function () {
    ActiveWwa::factory()->create([
        'product_id' => null, 'ugc_codes' => ['COZ040'], 'expires' => now()->addHour(),
        'vtec_office' => 'KBOU', 'vtec_phenomenon' => 'ZZ', 'vtec_significance' => 'Q', 'vtec_year' => 2026,
    ]);

    $event = (new GetActiveNowAtMyLocations)($this->user)['events'][0];

    expect($event['event'])->toBe('Weather alert')->and($event['family'])->toBeNull();
});

test('a delivery that covered the event as a later section of its bulletin counts as alerted', function () {
    $wwa = activeNowWarning();
    $rule = activeNowRule($this->user, 'Gulf rule');
    // The bulletin's own first VTEC line is a different event; the delivery covered this one through a segment.
    $bulletin = IngestedProduct::factory()->createQuietly([
        'vtec_office' => 'KBOU', 'vtec_phenomenon' => 'FF', 'vtec_significance' => 'W', 'vtec_event_number' => '0001', 'vtec_year' => 2026, 'is_test' => false,
    ]);
    $segment = IngestedProductSegment::factory()->for($bulletin)->create(ActiveWwa::vtecKeyFor($wwa));
    DeliveryLog::factory()->for($this->user)->create([
        'ingested_product_id' => $bulletin->id, 'alert_rule_id' => $rule->id, 'status' => 'success', 'dispatched_at' => now()->subMinutes(30),
    ])->segments()->attach($segment);

    $event = (new GetActiveNowAtMyLocations)($this->user)['events'][0];

    expect($event['status'])->toBe('alerted')
        ->and($event['alerted_via'])->toBe(['Gulf rule']);
});

test('a delivery linked only to other events of its bulletin does not count for an event the bulletin\'s first VTEC line names', function () {
    $wwa = activeNowWarning();
    $bulletin = IngestedProduct::factory()->createQuietly([...ActiveWwa::vtecKeyFor($wwa), 'is_test' => false]);
    $otherEvent = IngestedProductSegment::factory()->for($bulletin)->create([...ActiveWwa::vtecKeyFor($wwa), 'vtec_event_number' => '0999']);
    DeliveryLog::factory()->for($this->user)->create([
        'ingested_product_id' => $bulletin->id, 'status' => 'success', 'dispatched_at' => now()->subMinutes(30),
    ])->segments()->attach($otherEvent);

    expect((new GetActiveNowAtMyLocations)($this->user)['events'][0]['status'])->toBe('none');
});

test('a delivery with no linked events still counts through its bulletin\'s VTEC columns', function () {
    $wwa = activeNowWarning();
    activeNowDelivery($this->user, $wwa, 'success');

    expect((new GetActiveNowAtMyLocations)($this->user)['events'][0]['status'])->toBe('alerted');
});
