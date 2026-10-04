<?php

use App\Actions\Locations\GetLocationMap;
use App\Models\Location;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    Carbon::setTestNow('2026-10-03 12:00:00');
    $this->user = User::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('a stationary location is placed with its address, never stale, and an edit link', function () {
    $location = Location::factory()->for($this->user)->create([
        'label' => 'Home', 'address' => '1 Main St', 'lat' => 40.0, 'lng' => -105.0,
    ]);

    $result = (new GetLocationMap)([$location]);

    expect($result['unplaced'])->toBe(0)
        ->and($result['locations'])->toHaveCount(1)
        ->and($result['locations'][0])->toMatchArray([
            'key' => (string) $location->id,
            'label' => 'Home',
            'type' => 'stationary',
            'address' => '1 Main St',
            'lat' => 40.0,
            'lng' => -105.0,
            'stale' => false,
            'updated_at' => null,
        ])
        ->and($result['locations'][0]['lat'])->toBeFloat()
        ->and($result['locations'][0]['lng'])->toBeFloat()
        ->and($result['locations'][0]['edit_url'])->toEndWith("/locations/{$location->id}/edit");
});

test('a mobile location is fresh within the hour and stale beyond it or without a timestamp', function () {
    $fresh = Location::factory()->for($this->user)->mobile()->create(['label' => 'A fresh', 'lat' => 40.0, 'lng' => -105.0, 'mobile_position_updated_at' => now()->subMinutes(59)]);
    $stale = Location::factory()->for($this->user)->mobile()->create(['label' => 'B stale', 'lat' => 41.0, 'lng' => -106.0, 'mobile_position_updated_at' => now()->subMinutes(61)]);
    $unknown = Location::factory()->for($this->user)->mobile()->create(['label' => 'C unknown', 'lat' => 42.0, 'lng' => -107.0, 'mobile_position_updated_at' => null]);

    $result = (new GetLocationMap)([$fresh, $stale, $unknown]);

    expect(collect($result['locations'])->pluck('stale', 'label')->all())
        ->toBe(['A fresh' => false, 'B stale' => true, 'C unknown' => true])
        ->and($result['locations'][0]['type'])->toBe('mobile')
        ->and($result['locations'][0]['address'])->toBeNull()
        ->and($result['locations'][0]['updated_at'])->toBe(now()->subMinutes(59)->toIso8601String());
});

test('a mobile location with no position yet is left off the map and counted', function () {
    $mobileNoFix = Location::factory()->for($this->user)->mobile()->create(['label' => 'Truck']);
    $placed = Location::factory()->for($this->user)->create(['label' => 'Home', 'lat' => 40.0, 'lng' => -105.0]);

    $result = (new GetLocationMap)([$mobileNoFix, $placed]);

    expect(collect($result['locations'])->pluck('label')->all())->toBe(['Home'])
        ->and($result['unplaced'])->toBe(1);
});

test('a stationary location with null coordinates is skipped without error and not counted', function () {
    $broken = Location::factory()->for($this->user)->create(['label' => 'Broken', 'lat' => null, 'lng' => null]);

    expect((new GetLocationMap)([$broken]))->toBe(['locations' => [], 'unplaced' => 0]);
});

test('locations are ordered by label then id', function () {
    $b2 = Location::factory()->for($this->user)->create(['label' => 'B', 'lat' => 1.0, 'lng' => 1.0]);
    $a = Location::factory()->for($this->user)->create(['label' => 'A', 'lat' => 2.0, 'lng' => 2.0]);
    $b1 = Location::factory()->for($this->user)->create(['label' => 'B', 'lat' => 3.0, 'lng' => 3.0]);

    $result = (new GetLocationMap)([$b2, $a, $b1]);

    expect(collect($result['locations'])->pluck('key')->all())->toBe([(string) $a->id, (string) $b2->id, (string) $b1->id]);
});

test('the payload exposes nothing beyond the documented fields', function () {
    $location = Location::factory()->for($this->user)->mobile()->create(['lat' => 40.0, 'lng' => -105.0, 'mobile_position_updated_at' => now(), 'timezone' => 'America/Denver']);

    $entry = (new GetLocationMap)([$location])['locations'][0];

    expect(array_keys($entry))->toBe(['key', 'label', 'type', 'address', 'lat', 'lng', 'stale', 'updated_at', 'edit_url']);
});

test('an empty list yields an empty result', function () {
    expect((new GetLocationMap)([]))->toBe(['locations' => [], 'unplaced' => 0]);
});

test('a mobile location\'s resolved address is on the map, a blank one is null', function () {
    $resolved = Location::factory()->for($this->user)->mobile()->create(['label' => 'A resolved', 'lat' => 40.0, 'lng' => -105.0, 'address' => '1 Main St, Broomfield']);
    $blank = Location::factory()->for($this->user)->mobile()->create(['label' => 'B blank', 'lat' => 41.0, 'lng' => -106.0, 'address' => null]);

    $result = (new GetLocationMap)([$resolved, $blank]);

    expect(collect($result['locations'])->pluck('address', 'label')->all())->toBe(['A resolved' => '1 Main St, Broomfield', 'B blank' => null]);
});
