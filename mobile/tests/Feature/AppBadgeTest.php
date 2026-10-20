<?php

use App\Services\AppBadge;
use Illuminate\Support\Facades\Log;
use Native\Mobile\Testing\Native;
use Wxnotify\BadgeCount\Facades\BadgeCount;

test('set passes the count to the plugin', function () {
    BadgeCount::shouldReceive('set')->once()->with(3);

    app(AppBadge::class)->set(3);
});

test('a negative count becomes zero', function () {
    BadgeCount::shouldReceive('set')->once()->with(0);

    app(AppBadge::class)->set(-2);
});

test('clear sets the badge to zero', function () {
    BadgeCount::shouldReceive('set')->once()->with(0);

    app(AppBadge::class)->clear();
});

test('a plugin failure is swallowed and logged without its message', function () {
    Log::spy();
    BadgeCount::shouldReceive('set')->once()->andThrow(new RuntimeException('secret detail'));

    app(AppBadge::class)->set(4);

    Log::shouldHaveReceived('info')->once()->with('App badge set failed: '.RuntimeException::class);
});

test('the plugin sends the count to the native bridge', function () {
    $bridge = Native::fakeBridge();

    app(AppBadge::class)->set(5);

    $bridge->assertCalled('BadgeCount.Set', fn (array $params): bool => $params === ['count' => 5]);
});
