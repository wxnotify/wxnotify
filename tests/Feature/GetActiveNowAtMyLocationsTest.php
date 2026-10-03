<?php

use App\Actions\Alerts\GetActiveNowAtMyLocations;
use App\Actions\Weather\GetActiveAlertsForLocation;
use App\Models\ActiveWwa;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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

test('test warnings and locations without a position are ignored', function () {
    activeNowWarning('Test warning', attributes: ['is_test' => true]);
    Location::factory()->mobile()->for($this->user)->create(['label' => 'Truck']);
    $result = (new GetActiveNowAtMyLocations)($this->user);

    expect($result['events'])->toBe([]);
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
    Location::factory()->for($this->user)->count(25)->create(['lat' => 41.0, 'lng' => -100.0, 'forecast_zone_ugc' => 'COZ777', 'county_ugc' => 'COC777']);

    expect((new GetActiveNowAtMyLocations)($this->user)['capped'])->toBeTrue();

    Cache::flush();
    Location::query()->where('user_id', $this->user->id)->where('label', '!=', 'Home')->limit(5)->delete();

    expect((new GetActiveNowAtMyLocations)($this->user)['capped'])->toBeFalse();
});

test('a subscriber with no locations says so', function () {
    $loner = User::factory()->create();

    expect((new GetActiveNowAtMyLocations)($loner))->toBe(['events' => [], 'more' => 0, 'has_locations' => false, 'capped' => false]);
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
