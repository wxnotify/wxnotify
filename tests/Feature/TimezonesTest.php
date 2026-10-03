<?php

use App\Models\AlertRule;
use App\Models\Location;
use App\Models\User;
use App\Support\Timezones;

test('only canonical IANA identifiers are valid', function (?string $timezone, bool $valid) {
    expect(Timezones::isValid($timezone))->toBe($valid);
})->with([
    'a canonical zone' => ['America/Denver', true],
    'UTC' => ['UTC', true],
    'null' => [null, false],
    'an empty string' => ['', false],
    'garbage' => ['Mars/Olympus_Mons', false],
    'a legacy alias the timezone select never offers' => ['US/Mountain', false],
]);

test('forUser returns the validated profile timezone, else null', function () {
    expect(Timezones::forUser(null))->toBeNull()
        ->and(Timezones::forUser(User::factory()->make(['timezone' => null])))->toBeNull()
        ->and(Timezones::forUser(User::factory()->make(['timezone' => 'Mars/Olympus_Mons'])))->toBeNull()
        ->and(Timezones::forUser(User::factory()->make(['timezone' => 'America/Denver'])))->toBe('America/Denver');
});

test('forLocation prefers the location timezone, then the owner\'s profile timezone, then null', function () {
    $user = User::factory()->create(['timezone' => 'America/Chicago']);

    $own = Location::factory()->for($user)->create(['timezone' => 'Pacific/Honolulu']);
    $none = Location::factory()->for($user)->create(['timezone' => null]);

    expect(Timezones::forLocation($own))->toBe('Pacific/Honolulu')
        ->and(Timezones::forLocation($none))->toBe('America/Chicago');

    $user->update(['timezone' => null]);

    expect(Timezones::forLocation($none->fresh()))->toBeNull();
});

test('forRule prefers the location timezone, then the profile timezone, then the app timezone', function () {
    $user = User::factory()->create(['timezone' => 'America/Chicago']);
    $withZone = Location::factory()->for($user)->create(['timezone' => 'Pacific/Honolulu']);
    $withoutZone = Location::factory()->for($user)->create(['timezone' => null]);

    $ruleWithZone = AlertRule::factory()->dailySummary()->for($user)->for($withZone)->create();
    $ruleWithoutZone = AlertRule::factory()->dailySummary()->for($user)->for($withoutZone)->create();

    expect(Timezones::forRule($ruleWithZone))->toBe('Pacific/Honolulu')
        ->and(Timezones::forRule($ruleWithoutZone))->toBe('America/Chicago');

    $user->update(['timezone' => null]);

    expect(Timezones::forRule($ruleWithoutZone->fresh()))->toBe(config('app.timezone'));
});

test('normalise keeps a valid identifier and drops anything it cannot make valid', function (?string $timezone, ?string $expected) {
    expect(Timezones::normalise($timezone))->toBe($expected);
})->with([
    'a canonical zone' => ['America/Denver', 'America/Denver'],
    'garbage' => ['Mars/Olympus_Mons', null],
    'null' => [null, null],
    'an empty string' => ['', null],
]);

test('normalise turns a legacy alias into a valid canonical identifier or null, depending on the ICU build', function (string $alias) {
    $normalised = Timezones::normalise($alias);

    expect($normalised === null || Timezones::isValid($normalised))->toBeTrue()
        ->and($normalised)->not->toBe($alias);
})->with(['America/Indianapolis', 'America/Louisville', 'Asia/Calcutta', 'Etc/UTC']);
