<?php

use App\Actions\Locations\RecordMobilePosition;
use App\Jobs\ReverseGeocodeLocation;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->location = Location::factory()->for(User::factory())->mobileApp()->create();
});

test('a fix sets the position, the fix time and the receipt time', function () {
    // The timestamp columns keep whole seconds, so freeze on a whole second to compare exactly.
    $this->travelTo(now()->startOfSecond());
    $fixedAt = now()->subMinutes(2);

    $applied = app(RecordMobilePosition::class)($this->location, 35.2, -97.4, $fixedAt);

    $fresh = $this->location->fresh();
    expect($applied)->toBeTrue()
        ->and((float) $fresh->lat)->toBe(35.2)
        ->and((float) $fresh->lng)->toBe(-97.4)
        ->and($fresh->mobile_position_fixed_at->equalTo($fixedAt))->toBeTrue()
        ->and($fresh->mobile_position_updated_at->equalTo(now()))->toBeTrue();
});

test('a fix older than the stored one is ignored', function () {
    $this->travelTo(now()->startOfSecond());
    $storedFixAt = now();
    $this->location->forceFill(['lat' => 35.2, 'lng' => -97.4, 'mobile_position_fixed_at' => $storedFixAt])->save();

    $applied = app(RecordMobilePosition::class)($this->location, 10.0, 10.0, now()->subHour());

    $fresh = $this->location->fresh();
    expect($applied)->toBeFalse()
        ->and((float) $fresh->lat)->toBe(35.2)
        ->and((float) $fresh->lng)->toBe(-97.4)
        ->and($fresh->mobile_position_fixed_at->equalTo($storedFixAt))->toBeTrue();
});

test('a fix with the same time as the stored one is applied again', function () {
    // Whole seconds, so the stored fix time and the incoming one are exactly equal.
    $this->travelTo(now()->startOfSecond());
    $this->location->forceFill(['lat' => 35.2, 'lng' => -97.4, 'mobile_position_fixed_at' => now()])->save();

    $applied = app(RecordMobilePosition::class)($this->location, 35.3, -97.5, now());

    $fresh = $this->location->fresh();
    expect($applied)->toBeTrue()
        ->and((float) $fresh->lat)->toBe(35.3)
        ->and((float) $fresh->lng)->toBe(-97.5);
});

test('recording a position writes no activity row', function () {
    $before = Activity::count();

    app(RecordMobilePosition::class)($this->location, 35.2, -97.4, now());

    expect(Activity::count())->toBe($before);
});

test('an address lookup is queued for a first position and for a move of 100 m or more, not for a small one', function () {
    Queue::fake();
    // Reverse geocoding is on by default in tests; this makes the precondition explicit.
    setIntegrationSetting('reverse_geocoding_enabled', true);

    app(RecordMobilePosition::class)($this->location, 35.2, -97.4, now()->subMinutes(10));
    Queue::assertPushed(ReverseGeocodeLocation::class, 1);

    // The lookup job records where it resolved; pretend it has run.
    $this->location->refresh()->forceFill(['address_geocoded_lat' => 35.2, 'address_geocoded_lng' => -97.4])->save();
    Queue::fake();

    app(RecordMobilePosition::class)($this->location->fresh(), 35.2001, -97.4001, now()->subMinutes(5));
    Queue::assertNothingPushed();

    app(RecordMobilePosition::class)($this->location->fresh(), 35.3, -97.4, now());
    Queue::assertPushed(ReverseGeocodeLocation::class, 1);
});
