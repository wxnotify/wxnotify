<?php

use App\Models\Location;
use App\Models\User;
use App\Support\SunTimes;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

test('it returns today\'s sunrise and sunset in the location\'s timezone', function () {
    Carbon::setTestNow('2026-10-02 15:00:00'); // 09:00 America/Denver
    $location = Location::factory()->create(['lat' => 40.0031694, 'lng' => -104.997011, 'timezone' => 'America/Denver']);

    $sun = SunTimes::forLocation($location);

    expect(Carbon::parse($sun['sunrise'])->format('Y-m-d H:i'))->toBe('2026-10-02 06:57')
        ->and(Carbon::parse($sun['sunset'])->format('Y-m-d H:i'))->toBe('2026-10-02 18:40')
        ->and($sun['sunrise'])->toEndWith('-06:00');
});

test('a timezone east of UTC still gets its own local date, not the previous day\'s', function () {
    Carbon::setTestNow('2026-10-02 15:00:00'); // 2026-10-03 04:00 Pacific/Auckland
    $location = Location::factory()->create(['lat' => -36.85, 'lng' => 174.76, 'timezone' => 'Pacific/Auckland']);

    $sun = SunTimes::forLocation($location);

    expect(Carbon::parse($sun['sunrise'])->format('Y-m-d'))->toBe('2026-10-03')
        ->and(Carbon::parse($sun['sunset'])->format('Y-m-d'))->toBe('2026-10-03');
});

test('polar day, where the sun never sets, returns null', function () {
    Carbon::setTestNow('2026-06-21 12:00:00');
    $location = Location::factory()->create(['lat' => 80.0, 'lng' => 0.0, 'timezone' => 'UTC']);

    expect(SunTimes::forLocation($location))->toBeNull();
});

test('a location with no fix returns null', function () {
    expect(SunTimes::forLocation(Location::factory()->mobile()->create()))->toBeNull();
});

test('a location with no timezone gets sun times in the owner\'s profile timezone', function () {
    Carbon::setTestNow('2026-10-02 15:00:00'); // 09:00 America/Denver
    $location = Location::factory()->create([
        'user_id' => User::factory()->create(['timezone' => 'America/Denver'])->id,
        'lat' => 40.0031694, 'lng' => -104.997011, 'timezone' => null,
    ]);

    $sun = SunTimes::forLocation($location);

    expect(Carbon::parse($sun['sunrise'])->format('Y-m-d H:i'))->toBe('2026-10-02 06:57')
        ->and($sun['sunrise'])->toEndWith('-06:00');
});

test('a location with no timezone and an owner with none gets sun times in the app timezone', function () {
    Carbon::setTestNow('2026-10-02 15:00:00');
    $location = Location::factory()->create([
        'user_id' => User::factory()->create(['timezone' => null])->id,
        'lat' => 40.0031694, 'lng' => -104.997011, 'timezone' => null,
    ]);

    expect(SunTimes::forLocation($location)['sunrise'])->toEndWith('+00:00');
});

test('a location with its own timezone ignores the owner\'s profile timezone for sun times', function () {
    Carbon::setTestNow('2026-10-02 15:00:00');
    $location = Location::factory()->create([
        'user_id' => User::factory()->create(['timezone' => 'Pacific/Honolulu'])->id,
        'lat' => 40.0031694, 'lng' => -104.997011, 'timezone' => 'America/Denver',
    ]);

    expect(SunTimes::forLocation($location)['sunrise'])->toEndWith('-06:00');
});
