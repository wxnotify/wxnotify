<?php

use App\Services\PushRegistration;
use App\Services\SignOut;
use App\Support\PushState;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\PushNotification\TokenGenerated;
use Native\Mobile\Facades\PushNotifications;
use Native\Mobile\Facades\System;

beforeEach(function () {
    (new TokenStore)->put('9|secret');
    System::shouldReceive('isIos')->andReturn(false)->byDefault();
});

function pushRegistered(string $token = 'fcm-1', string $platform = 'android'): Closure
{
    return fn (Request $r) => $r->method() === 'PUT'
        && $r->url() === 'https://wx.test/api/v1/push-token'
        && $r['token'] === $token
        && $r['platform'] === $platform;
}

test('the prompt is offered only while undecided and the OS has not refused', function (?string $permission, bool $declined, bool $enabled, bool $expected) {
    PushNotifications::shouldReceive('checkPermission')->andReturn($permission);
    $state = new PushState;
    $declined ? $state->markDeclined() : null;
    $enabled ? $state->markEnabled() : null;

    expect(app(PushRegistration::class)->shouldPrompt())->toBe($expected);
})->with([
    'not asked yet' => ['not_determined', false, false, true],
    'already granted, never opted in' => ['granted', false, false, true],
    'said not now' => ['not_determined', true, false, false],
    'already on' => ['granted', false, true, false],
    'refused by the OS' => ['denied', false, false, false],
    'not on a device' => [null, false, false, false],
]);

test('enabling remembers the choice and asks the OS to enroll', function () {
    PushNotifications::shouldReceive('enroll')->once();

    app(PushRegistration::class)->enable();

    expect((new PushState)->wantsPush())->toBeTrue();
});

test('"not now" is remembered and never undone by turning push on later', function () {
    $registration = app(PushRegistration::class);
    $registration->decline();

    expect((new PushState)->declined())->toBeTrue();

    PushNotifications::shouldReceive('enroll')->once();
    $registration->enable();

    expect((new PushState)->declined())->toBeFalse()->and((new PushState)->wantsPush())->toBeTrue();
});

test('a token the OS hands over is registered with the server and the platform', function (bool $ios, string $platform) {
    System::shouldReceive('isIos')->andReturn($ios);
    Http::fake(['wx.test/api/v1/push-token' => Http::response(null, 204)]);
    (new PushState)->markEnabled();

    event(new TokenGenerated('fcm-1'));

    Http::assertSent(pushRegistered('fcm-1', $platform));
    expect((new PushState)->registeredWithin(1))->toBeTrue();
})->with([[false, 'android'], [true, 'ios']]);

test('a token arriving after the subscriber turned push off, or signed out, registers nothing', function () {
    Http::fake();

    event(new TokenGenerated('fcm-1'));
    (new TokenStore)->forget();
    (new PushState)->markEnabled();
    event(new TokenGenerated('fcm-1'));

    Http::assertNothingSent();
});

test('a server refusal while registering is swallowed so the app carries on', function () {
    Http::fake(['wx.test/api/v1/push-token' => Http::response([], 503)]);
    (new PushState)->markEnabled();

    event(new TokenGenerated('fcm-1'));

    expect((new PushState)->registeredWithin(1))->toBeFalse();
});

test('turning push off tells the server and remembers it', function () {
    Http::fake(['wx.test/api/v1/push-token' => Http::response(null, 204)]);
    (new PushState)->markEnabled();

    $done = app(PushRegistration::class)->disable();

    expect($done)->toBeTrue()->and((new PushState)->wantsPush())->toBeFalse()->and((new PushState)->declined())->toBeTrue();
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://wx.test/api/v1/push-token');
});

test('push stays on when the server could not be told it is off', function () {
    Http::fake(['wx.test/api/v1/push-token' => Http::response([], 503)]);
    (new PushState)->markEnabled();

    expect(app(PushRegistration::class)->disable())->toBeFalse()->and((new PushState)->wantsPush())->toBeTrue();
});

test('the token is re-registered on launch once a day while push is on', function () {
    Http::fake(['wx.test/api/v1/push-token' => Http::response(null, 204)]);
    PushNotifications::shouldReceive('getToken')->andReturn('fcm-1');
    (new PushState)->markEnabled();

    app(PushRegistration::class)->refreshIfDue();
    app(PushRegistration::class)->refreshIfDue();

    Http::assertSentCount(1);
    Http::assertSent(pushRegistered('fcm-1'));
});

test('nothing is re-registered while push is off or there is no token yet', function () {
    Http::fake();
    PushNotifications::shouldReceive('getToken')->andReturn(null);

    app(PushRegistration::class)->refreshIfDue();
    (new PushState)->markEnabled();
    app(PushRegistration::class)->refreshIfDue();

    Http::assertNothingSent();
});

test('signing out forgets every push flag', function () {
    $state = new PushState;
    $state->markEnabled();
    $state->markDeclined();
    $state->markRegistered();
    Http::fake(['*' => Http::response(null, 204)]);

    app(SignOut::class)();

    expect($state->wantsPush())->toBeFalse()->and($state->declined())->toBeFalse()->and($state->registeredWithin(48))->toBeFalse();
});
