<?php

use App\Actions\Pipeline\BuildBulletinSegments;
use App\Actions\Weather\GetActiveAlertsForLocation;
use App\Models\ActiveWwa;
use App\Models\ActiveWwaArea;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 22:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A stored CAP message for one zone of the Mobile Hurricane Watch, with its segment built, made without the observer. */
function rebuildStoredMessage(string $zone, string $action, Carbon $receivedAt, array $attributes = []): IngestedProduct
{
    $bulletin = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'format' => 'cap', 'text' => '<?xml version="1.0"?><alert/>', 'cccc' => 'kwbc', 'is_test' => false,
        'product_id' => Product::where('code', 'HU.A')->sole()->id,
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A', 'vtec_event_number' => '1009', 'vtec_year' => 2026,
        'vtec_action' => $action, 'ugc_codes' => [$zone], 'received_at' => $receivedAt, ...$attributes,
    ]));
    app(BuildBulletinSegments::class)($bulletin);

    return $bulletin;
}

/** The event as the old code left it: one zone, the last one seen. */
function rebuildCollapsedEvent(array $attributes = []): ActiveWwa
{
    $event = ActiveWwa::factory()->create([
        'product_id' => Product::where('code', 'HU.A')->sole()->id,
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A', 'vtec_event_number' => '1009', 'vtec_year' => 2026,
        'ugc_codes' => ['FLZ203'], 'expires' => null, 'status' => 'Active', 'is_test' => false, ...$attributes,
    ]);
    ActiveWwaArea::factory()->for($event)->create(['ugc_code' => 'FLZ203', 'expires' => null]);

    return $event;
}

function rebuildZoneStates(ActiveWwa $event): array
{
    return $event->areas()->orderBy('ugc_code')->pluck('status', 'ugc_code')->all();
}

test('restores the zones a collapsed event lost, so Destin sees the Hurricane Watch again', function () {
    $destin = Location::factory()->create(['lat' => 30.39, 'lng' => -86.45, 'forecast_zone_ugc' => 'FLZ206', 'county_ugc' => 'FLC091']);
    $event = rebuildCollapsedEvent();
    rebuildStoredMessage('FLZ206', 'NEW', now()->subHours(6));
    rebuildStoredMessage('ALZ266', 'NEW', now()->subHours(6)->addMinute());
    rebuildStoredMessage('FLZ203', 'CON', now()->subHour());
    expect(app(GetActiveAlertsForLocation::class)->activeFor($destin)->isEmpty())->toBeTrue();

    $this->artisan('active-wwas:rebuild')->expectsOutputToContain('Rebuilt the zones of 1 active event')->assertSuccessful();

    expect(rebuildZoneStates($event))->toBe(['ALZ266' => 'Active', 'FLZ203' => 'Active', 'FLZ206' => 'Active'])
        ->and(app(GetActiveAlertsForLocation::class)->activeFor($destin)->modelKeys())->toBe([$event->id]);
});

test('replays the stored bulletins oldest first, so a cancel ends only its own zone', function () {
    $event = rebuildCollapsedEvent();
    // Stored out of order on purpose: the replay orders by when each bulletin was received.
    rebuildStoredMessage('FLZ206', 'CAN', now()->subHour());
    rebuildStoredMessage('FLZ206', 'NEW', now()->subHours(6));
    rebuildStoredMessage('FLZ203', 'NEW', now()->subHours(6));

    $this->artisan('active-wwas:rebuild')->assertSuccessful();

    expect(rebuildZoneStates($event))->toBe(['FLZ203' => 'Active', 'FLZ206' => 'Cancelled'])
        ->and($event->fresh()->status)->toBe('Active');
});

test('an event whose stored bulletins end every zone is brought up to date as ended', function () {
    $event = rebuildCollapsedEvent();
    rebuildStoredMessage('FLZ203', 'NEW', now()->subHours(6));
    rebuildStoredMessage('FLZ203', 'CAN', now()->subHour());

    $this->artisan('active-wwas:rebuild')->assertSuccessful();

    expect($event->fresh()->status)->toBe('Cancelled');
});

test('running it again changes nothing', function () {
    $event = rebuildCollapsedEvent();
    rebuildStoredMessage('FLZ206', 'NEW', now()->subHours(6));
    rebuildStoredMessage('FLZ203', 'NEW', now()->subHours(6));

    $this->artisan('active-wwas:rebuild')->assertSuccessful();
    $first = rebuildZoneStates($event);
    $this->artisan('active-wwas:rebuild')->assertSuccessful();

    expect(rebuildZoneStates($event))->toBe($first)
        ->and($event->areas()->count())->toBe(2);
});

test('events that are cancelled, or have no stored bulletin in the window, are left alone', function () {
    $cancelled = rebuildCollapsedEvent(['status' => 'Cancelled', 'vtec_event_number' => '2001']);
    rebuildStoredMessage('FLZ206', 'NEW', now()->subHours(6), ['vtec_event_number' => '2001']);
    $stale = rebuildCollapsedEvent(['vtec_event_number' => '2002']);
    rebuildStoredMessage('FLZ206', 'NEW', now()->subDays(5), ['vtec_event_number' => '2002']);

    $this->artisan('active-wwas:rebuild')->expectsOutputToContain('Rebuilt the zones of 0 active events')->assertSuccessful();

    expect(rebuildZoneStates($cancelled))->toBe(['FLZ203' => 'Active'])
        ->and(rebuildZoneStates($stale))->toBe(['FLZ203' => 'Active']);
});

test('--days widens the window', function () {
    $stale = rebuildCollapsedEvent();
    rebuildStoredMessage('FLZ206', 'NEW', now()->subDays(5));

    $this->artisan('active-wwas:rebuild', ['--days' => 7])->assertSuccessful();

    expect(rebuildZoneStates($stale))->toBe(['FLZ203' => 'Active', 'FLZ206' => 'Active']);
});

test('a test event is rebuilt only from test bulletins', function () {
    $real = rebuildCollapsedEvent(['is_test' => false]);
    rebuildStoredMessage('FLZ206', 'NEW', now()->subHours(6), ['is_test' => true]);
    rebuildStoredMessage('ALZ266', 'NEW', now()->subHours(6));

    $this->artisan('active-wwas:rebuild')->assertSuccessful();

    expect(rebuildZoneStates($real))->toBe(['ALZ266' => 'Active', 'FLZ203' => 'Active']);
});

test('sends nothing, queues nothing and writes no delivery log', function () {
    Queue::fake();
    rebuildCollapsedEvent();
    rebuildStoredMessage('FLZ206', 'NEW', now()->subHours(6));

    $this->artisan('active-wwas:rebuild')->assertSuccessful();

    Queue::assertNothingPushed();
    expect(DeliveryLog::count())->toBe(0);
});

test('--days must be at least 1', function () {
    $this->artisan('active-wwas:rebuild', ['--days' => 0])
        ->expectsOutputToContain('--days must be at least 1')
        ->assertFailed();
});

test('keeps a zone row that no stored bulletin names, while rebuilding the zones they do', function () {
    $event = rebuildCollapsedEvent();
    ActiveWwaArea::factory()->for($event)->create(['ugc_code' => 'FLZ299', 'status' => 'Active', 'expires' => null]);
    rebuildStoredMessage('FLZ206', 'NEW', now()->subHours(6));
    rebuildStoredMessage('FLZ203', 'CAN', now()->subHour());

    $this->artisan('active-wwas:rebuild')->assertSuccessful();

    expect(rebuildZoneStates($event))->toBe(['FLZ203' => 'Cancelled', 'FLZ206' => 'Active', 'FLZ299' => 'Active']);
});
