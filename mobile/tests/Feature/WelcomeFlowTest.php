<?php

use App\NativeComponents\WelcomeFlow;
use App\Support\LocationSharingState;
use App\Support\PushState;
use App\Support\TokenStore;
use App\Support\WelcomeState;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\PushNotification\TokenGenerated;
use Native\Mobile\Facades\PushNotifications;
use Native\Mobile\Facades\System;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    (new TokenStore)->put('9|secret');
    System::shouldReceive('isIos')->andReturn(false)->byDefault();
    PushNotifications::shouldReceive('checkPermission')->andReturn('not_determined')->byDefault();
});

/**
 * Fakes the free-slot lookup (and, when given, the location-sharing start) the flow makes.
 *
 * @param  array<string, mixed>  $more
 */
function fakeWelcomeFlowServer(bool $slotFree = true, array $more = []): void
{
    Http::fake([
        'wx.test/api/v1/alert-rule-options' => Http::response(['data' => ['can_share_phone_location' => $slotFree]]),
        ...$more,
    ]);
}

function welcomeFlowLocationStarts(): int
{
    return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/api/v1/location-sharing'))->count();
}

test('it walks the five steps with Next and finishes on Alerts', function () {
    fakeWelcomeFlowServer();
    PushNotifications::shouldReceive('enroll')->never();

    Native::test(WelcomeFlow::class)
        ->assertSet('steps', ['welcome', 'offer', 'push', 'location', 'done'])
        ->assertSee('Welcome to WxNotify')
        ->assertSee('Weather alerts for the places you care about, on your phone.')
        ->tap('Next')
        ->assertSee('What you get')
        ->assertSee('Alerts you set up once. Current warnings and advisories for your places. A map and every National Weather Service product.')
        ->tap('Next')
        ->assertSee('Get alerts instantly')
        ->assertSee('Allow notifications so a warning reaches you the moment it\'s issued, even when the app is closed.')
        ->assertSee('Allow notifications')
        ->tap('Not now')
        ->assertSee('Follow your phone')
        ->assertSee('Share this phone\'s location and we\'ll watch the weather wherever you are. It uses one of your places.')
        ->assertSee('Your phone will ask for location access. To keep sharing after you close the app, choose "Always" or "Allow all the time" if your phone offers it.')
        ->assertSee('Share location')
        ->tap('Not now')
        ->assertSee('You\'re set')
        ->assertSee('Add places and alert rules any time from Settings.')
        ->assertDontSee('allowance is used up')
        ->tap('Go to Alerts')
        ->assertReplacedWith('/');

    expect(welcomeFlowLocationStarts())->toBe(0)
        ->and((new LocationSharingState)->wanted())->toBeFalse();
});

test('Skip on any step leaves for Alerts and asks for nothing', function (int $nexts) {
    fakeWelcomeFlowServer();
    PushNotifications::shouldReceive('enroll')->never();

    $flow = Native::test(WelcomeFlow::class);

    for ($i = 0; $i < $nexts; $i++) {
        $flow->call('next');
    }

    $flow->tap('Skip')->assertReplacedWith('/');

    expect(welcomeFlowLocationStarts())->toBe(0)
        ->and((new PushState)->declined())->toBeFalse()
        ->and((new PushState)->wantsPush())->toBeFalse()
        ->and((new LocationSharingState)->wanted())->toBeFalse();
})->with([
    'welcome' => 0,
    'what you get' => 1,
    'push' => 2,
    'location' => 3,
    'all set' => 4,
]);

test('Allow notifications enables push and moves on', function () {
    fakeWelcomeFlowServer();
    PushNotifications::shouldReceive('enroll')->once();

    Native::test(WelcomeFlow::class)
        ->call('next')
        ->call('next')
        ->tap('Allow notifications')
        ->assertSee('Follow your phone')
        ->assertNoNavigation();

    expect((new PushState)->wantsPush())->toBeTrue();
});

test('Not now on push declines and moves on', function () {
    fakeWelcomeFlowServer();
    PushNotifications::shouldReceive('enroll')->never();

    Native::test(WelcomeFlow::class)
        ->call('next')
        ->call('next')
        ->tap('Not now')
        ->assertSee('Follow your phone')
        ->assertNoNavigation();

    expect((new PushState)->declined())->toBeTrue()
        ->and((new PushState)->wantsPush())->toBeFalse();
});

test('a double tap on Allow notifications enrols once and stops on the location screen', function () {
    fakeWelcomeFlowServer();
    PushNotifications::shouldReceive('enroll')->once();

    Native::test(WelcomeFlow::class)
        ->call('next')
        ->call('next')
        ->call('allowPush')
        ->call('allowPush')
        ->assertSee('Follow your phone');
});

test('a double tap on Share location starts sharing once and shows no full-plan note', function () {
    fakeWelcomeFlowServer(more: ['wx.test/api/v1/location-sharing' => Http::sequence()
        ->push(['data' => ['location_id' => 7, 'beacon_token' => '12|b', 'url' => 'https://wx.test/api/v1/location', 'interval_seconds' => 300]])
        ->push(['error' => 'location_limit', 'message' => 'x'], 422)]);

    Native::test(WelcomeFlow::class)
        ->call('next')
        ->call('next')
        ->call('notNowPush')
        ->call('shareLocation')
        ->call('shareLocation')
        ->assertSee('You\'re set')
        ->assertSet('slotNotice', null)
        ->assertDontSee('allowance is used up');

    expect(welcomeFlowLocationStarts())->toBe(1);
});

test('a double tap on a Not now button moves on only once', function () {
    fakeWelcomeFlowServer();

    Native::test(WelcomeFlow::class)
        ->call('next')
        ->call('next')
        ->call('notNowPush')
        ->call('notNowPush')
        ->assertSee('Follow your phone')
        ->call('notNowLocation')
        ->call('notNowLocation')
        ->assertSee('You\'re set');
});

test('Share location starts sharing and moves on', function () {
    fakeWelcomeFlowServer(more: ['wx.test/api/v1/location-sharing' => Http::response(['data' => ['location_id' => 7, 'beacon_token' => '12|b', 'url' => 'https://wx.test/api/v1/location', 'interval_seconds' => 300]])]);

    Native::test(WelcomeFlow::class)
        ->call('next')
        ->call('next')
        ->call('notNowPush')
        ->tap('Share location')
        ->assertSee('You\'re set')
        ->assertSet('note', null)
        ->assertDontSee('allowance is used up');

    expect((new LocationSharingState)->wanted())->toBeTrue()
        ->and($this->geolocation->shared)->toHaveCount(1);
});

test('a location limit moves on and the last screen shows the full-plan note', function () {
    fakeWelcomeFlowServer(more: ['wx.test/api/v1/location-sharing' => Http::response(['error' => 'location_limit', 'message' => 'x'], 422)]);

    Native::test(WelcomeFlow::class)
        ->call('next')
        ->call('next')
        ->call('notNowPush')
        ->tap('Share location')
        ->assertSee('You\'re set')
        ->assertSee('Your plan\'s phone-location allowance is used up, so sharing this phone\'s location isn\'t available.');

    expect((new LocationSharingState)->wanted())->toBeFalse();
});

test('a location start that is offline or fails moves on with a one-line note', function (bool $online, string $note) {
    fakeWelcomeFlowServer(more: ['wx.test/api/v1/location-sharing' => Http::response([], 503)]);
    $flow = Native::test(WelcomeFlow::class)->call('next')->call('next')->call('notNowPush');
    $this->network->connected = $online;

    $flow->tap('Share location')
        ->assertSee('You\'re set')
        ->assertSee($note)
        ->assertDontSee('allowance is used up');

    expect((new LocationSharingState)->wanted())->toBeFalse();
})->with([
    'offline' => [false, 'Couldn\'t start sharing: you appear to be offline. You can turn it on later from Account.'],
    'failed' => [true, 'Couldn\'t start sharing right now. You can turn it on later from Account.'],
]);

test('the push and location steps are absent when the rules exclude them', function () {
    fakeWelcomeFlowServer(slotFree: false);
    (new PushState)->markEnabled();
    PushNotifications::shouldReceive('checkPermission')->andReturn('granted');

    Native::test(WelcomeFlow::class)
        ->assertSet('steps', ['welcome', 'offer', 'done'])
        ->assertSet('slotNotice', 'used_up')
        ->tap('Next')
        ->tap('Next')
        ->assertSee('You\'re set')
        ->assertSee('Your plan\'s phone-location allowance is used up, so sharing this phone\'s location isn\'t available.');
});

test('a slot lookup that failed or had no answer leaves out Location with a neutral note', function (mixed $response) {
    Http::fake(['wx.test/api/v1/alert-rule-options' => $response]);

    Native::test(WelcomeFlow::class)
        ->assertSet('steps', ['welcome', 'offer', 'push', 'done'])
        ->assertSet('slotNotice', 'unknown')
        ->call('next')
        ->call('next')
        ->call('notNowPush')
        ->assertSee('You\'re set')
        ->assertSee('You can share this phone\'s location later from Settings → Account.')
        ->assertDontSee('allowance is used up');
})->with([
    'key missing' => fn () => Http::response(['data' => ['can_create_location' => true]]),
    'server error' => fn () => Http::response([], 500),
    'rate limited' => fn () => Http::response([], 429),
    'timed out' => fn () => Http::failedConnection('timed out'),
]);

test('an offline phone leaves out Location with the neutral note', function () {
    Http::fake();
    $this->network->connected = false;

    Native::test(WelcomeFlow::class)
        ->assertSet('steps', ['welcome', 'offer', 'push', 'done'])
        ->assertSet('slotNotice', 'unknown');

    Http::assertNothingSent();
});

test('sharing that is waiting for permission leaves out Location with no note', function () {
    fakeWelcomeFlowServer();
    (new LocationSharingState)->markWanted();

    Native::test(WelcomeFlow::class)
        ->assertSet('steps', ['welcome', 'offer', 'push', 'done'])
        ->assertSet('slotNotice', null);
});

test('the dots say which step is showing', function () {
    fakeWelcomeFlowServer();

    Native::test(WelcomeFlow::class)
        ->assertElement('row', fn (array $node) => ($node['props']['a11y_label'] ?? null) === 'Step 1 of 5')
        ->tap('Next')
        ->assertElement('row', fn (array $node) => ($node['props']['a11y_label'] ?? null) === 'Step 2 of 5');
});

test('a double tap on Next moves on only once', function () {
    fakeWelcomeFlowServer();

    Native::test(WelcomeFlow::class)
        ->call('next', 0)
        ->call('next', 0)
        ->assertSet('index', 1)
        ->assertSee('What you get');
});

test('the location step left out because sharing is already on shows no full-plan note', function () {
    fakeWelcomeFlowServer();
    $this->geolocation->status = ['id' => 'wxnotify-location', 'shareActive' => true, 'shareIntervalMs' => 300000, 'lastShareStatus' => 204];

    Native::test(WelcomeFlow::class)
        ->assertSet('steps', ['welcome', 'offer', 'push', 'done'])
        ->assertSet('slotNotice', null);
});

test('a signed-out visitor is sent to sign in and the flag is left alone', function () {
    (new TokenStore)->forget();
    Http::fake();

    Native::visit('/welcome')->assertReplacedWith('/sign-in');

    expect((new WelcomeState)->seen())->toBeFalse();
    Http::assertNothingSent();
});

test('the first run marks the flow seen', function () {
    fakeWelcomeFlowServer();

    Native::visit('/welcome')->assertSet('replay', false)->assertNoNavigation();

    expect((new WelcomeState)->seen())->toBeTrue();
});

test('replay does not touch the seen flag and Skip returns to settings', function () {
    fakeWelcomeFlowServer();

    Native::visit('/welcome/tour')->assertSet('replay', true)->tap('Skip')->assertWentBack();

    expect((new WelcomeState)->seen())->toBeFalse();
});

test('finishing a replay returns to settings without touching the flag', function () {
    fakeWelcomeFlowServer(slotFree: false);
    (new PushState)->markEnabled();

    Native::visit('/welcome/tour')
        ->tap('Next')
        ->tap('Next')
        ->assertDontSee('Go to Alerts')
        ->tap('Done')
        ->assertWentBack();

    expect((new WelcomeState)->seen())->toBeFalse();
});

test('the flow registers a push token the plugin hands over while push is on', function () {
    fakeWelcomeFlowServer(more: ['wx.test/api/v1/push-token' => Http::response(null, 204)]);
    PushNotifications::shouldReceive('enroll')->once();

    Native::test(WelcomeFlow::class)
        ->call('next')
        ->call('next')
        ->tap('Allow notifications')
        ->emitNative(TokenGenerated::class, ['token' => 'fcm-1', 'id' => null]);

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://wx.test/api/v1/push-token' && $r['token'] === 'fcm-1');
});
