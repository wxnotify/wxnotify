<?php

use App\Services\DeviceTimezone;
use Illuminate\Support\Facades\Log;
use Native\Mobile\Testing\Native;
use Wxnotify\BadgeCount\Facades\BadgeCount;

/** The suite binds a fake by default; these tests exercise the real service. */
beforeEach(fn () => app()->forgetInstance(DeviceTimezone::class));

test('a valid zone id from the plugin is returned', function () {
    BadgeCount::shouldReceive('timezone')->once()->andReturn('Europe/Paris');

    expect(app(DeviceTimezone::class)->id())->toBe('Europe/Paris');
});

test('an unknown, empty or missing id is null', function (?string $answer) {
    BadgeCount::shouldReceive('timezone')->once()->andReturn($answer);

    expect(app(DeviceTimezone::class)->id())->toBeNull();
})->with([null, '', 'Not/AZone', 'GMT+9']);

test('a plugin failure is swallowed and logged without its message', function () {
    Log::spy();
    BadgeCount::shouldReceive('timezone')->once()->andThrow(new RuntimeException('secret detail'));

    expect(app(DeviceTimezone::class)->id())->toBeNull();

    Log::shouldHaveReceived('info')->once()->with('Device timezone read failed: '.RuntimeException::class);
});

test('the plugin asks the native bridge and reads the id from its answer', function () {
    $bridge = Native::fakeBridge();
    $bridge->respondTo('BadgeCount.Timezone', ['status' => 'success', 'data' => ['timezone' => 'Asia/Tokyo']]);

    expect(app(DeviceTimezone::class)->id())->toBe('Asia/Tokyo');
    $bridge->assertCalled('BadgeCount.Timezone');
});
