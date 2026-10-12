<?php

use App\Support\ActiveAlertsPresenter;

function presenterEvent(array $overrides = []): array
{
    return ['id' => 1, 'event' => 'Tornado Warning', 'family' => 'warning', 'places' => ['Cabin', 'Home'], 'expires' => null, 'view_url' => null, ...$overrides];
}

beforeEach(fn () => $this->freezeSecond());

test('the overline is the family and when the event ends, or that it has no end time', function () {
    expect(ActiveAlertsPresenter::overline(presenterEvent(['expires' => now()->addHours(3)->toIso8601String()])))->toBe('Warning · Ends 3 hours from now')
        ->and(ActiveAlertsPresenter::overline(presenterEvent(['family' => 'watch'])))->toBe('Watch · No end time given')
        ->and(ActiveAlertsPresenter::overline(presenterEvent(['family' => 'advisory', 'expires' => now()->addMinutes(30)->toIso8601String()])))->toStartWith('Advisory · Ends ');
});

test('the places read as a comma-separated line', function () {
    expect(ActiveAlertsPresenter::places(presenterEvent()))->toBe('Cabin, Home');
});

test('events whose end has passed are dropped, and events with no end stay', function () {
    $events = [
        presenterEvent(['id' => 1, 'expires' => now()->subMinute()->toIso8601String()]),
        presenterEvent(['id' => 2, 'expires' => now()->addMinute()->toIso8601String()]),
        presenterEvent(['id' => 3, 'expires' => null]),
    ];

    expect(array_column(ActiveAlertsPresenter::withoutEnded($events), 'id'))->toBe([2, 3]);
});

test('the first five show unless expanded, and the hidden count says how many are left', function () {
    $events = array_map(fn (int $id) => presenterEvent(['id' => $id]), range(1, 8));

    expect(array_column(ActiveAlertsPresenter::visible($events, false), 'id'))->toBe([1, 2, 3, 4, 5])
        ->and(ActiveAlertsPresenter::hiddenCount($events, false))->toBe(3)
        ->and(count(ActiveAlertsPresenter::visible($events, true)))->toBe(8)
        ->and(ActiveAlertsPresenter::hiddenCount($events, true))->toBe(0)
        ->and(ActiveAlertsPresenter::hiddenCount(array_slice($events, 0, 5), false))->toBe(0);
});

test('as-of reads in the subscriber timezone when known, else how long ago', function () {
    $iso = '2026-10-12T19:14:00+00:00';

    expect(ActiveAlertsPresenter::asOf($iso, 'America/Chicago'))->toBe('As of Oct 12, 2:14 PM CDT')
        ->and(ActiveAlertsPresenter::asOf(now()->subMinutes(5)->toIso8601String(), null))->toBe('As of 5 minutes ago');
});
