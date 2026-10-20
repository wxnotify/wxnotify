<?php

use App\Support\AlertPresenter;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow('2026-10-04 12:00:00'));

test('times read as relative text', function () {
    expect(AlertPresenter::when('2026-10-04T11:57:00+00:00'))->toBe('3 minutes ago');
});

test('an absolute time needs the subscriber\'s timezone', function () {
    expect(AlertPresenter::absolute('2026-10-04T20:42:00+00:00', 'America/Chicago'))->toBe('Oct 4, 3:42 PM CDT')
        ->and(AlertPresenter::absolute('2026-10-04T20:42:00+00:00', null))->toBeNull();
});

test('the subtitle joins the category and the relative time', function () {
    expect(AlertPresenter::subtitle(['category_label' => 'Weather Alerts', 'dispatched_at' => '2026-10-04T11:57:00+00:00']))
        ->toBe('Weather Alerts · 3 minutes ago');
});

test('the subtitle is just the relative time when the category is null, empty or blank', function (?string $label) {
    expect(AlertPresenter::subtitle(['category_label' => $label, 'dispatched_at' => '2026-10-04T11:57:00+00:00']))
        ->toBe('3 minutes ago')
        ->and(AlertPresenter::subtitle(['dispatched_at' => '2026-10-04T11:57:00+00:00']))->toBe('3 minutes ago');
})->with([null, '', '  ']);
