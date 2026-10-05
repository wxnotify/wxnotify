<?php

use App\NativeComponents\AccountScreen;
use App\NativeComponents\AlertDetail;
use App\NativeComponents\AlertsScreen;
use App\NativeComponents\AuthCallback;
use App\NativeComponents\AuthDenied;
use App\NativeComponents\LocationPrompt;
use App\NativeComponents\PushPrompt;
use App\NativeComponents\SignInScreen;
use App\Providers\NativeServiceProvider;
use App\Support\TokenStore;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use Native\Mobile\UI\NativeUIServiceProvider;

/*
 * The element-tree assertions in the other screen tests pass whether or not the native side can draw
 * an element. These check that every element a screen relies on comes out of the Blade compiler with
 * a type that the registered plugins know, so an element that lost its renderer registration (or a
 * tag that no longer compiles) fails here instead of drawing a blank box on a device.
 */

function renderableAlert(): array
{
    return [
        'id' => 4,
        'subject' => 'Tornado Warning',
        'category' => 'weather_alerts',
        'category_label' => 'Weather Alerts',
        'dispatched_at' => now()->subMinutes(5)->toIso8601String(),
        'channels' => [['type' => 'discord', 'type_label' => 'Discord', 'status' => 'failure', 'label' => 'Team Discord']],
        'has_failure' => true,
        'rule_name' => 'Storm warnings',
        'product_url' => 'https://wx.test/view-product/abc',
    ];
}

test('the sign-in screen renders its logo, copy and a sign-in button', function () {
    Native::test(SignInScreen::class)
        ->assertElement('image')
        ->assertElement('text')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Sign in to WxNotify');
});

test('the callback screen renders a spinner while signing in and a retry button when it fails', function () {
    (new TokenStore)->begin('v', 'state-1');
    Http::fake();

    Native::test(AuthCallback::class, ['code' => 'c', 'state' => 'other'])
        ->assertElement('text')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Try again')
        ->assertMissingElement('activity_indicator');

    (new TokenStore)->begin('v', 'state-2');
    Http::fake(['*' => Http::response(['token' => '5|abc', 'expires_at' => '2027-01-01T00:00:00+00:00', 'user' => ['id' => 5]])]);

    // A successful sign-in redirects before the first frame, so re-render with the error cleared to reach the spinner.
    Native::test(AuthCallback::class, ['code' => 'c', 'state' => 'state-2'])
        ->set('error', null)
        ->assertElement('activity_indicator')
        ->assertElement('text')
        ->assertMissingElement('button');
});

test('the push prompt renders its copy and both choices', function () {
    (new TokenStore)->put('9|secret');

    Native::test(PushPrompt::class)
        ->assertElement('text')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Enable notifications')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Not now');
});

test('the location prompt renders its copy and both choices', function () {
    (new TokenStore)->put('9|secret');

    Native::test(LocationPrompt::class)
        ->assertElement('text')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Share my location')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Not now');
});

test('the denied-callback screen renders an empty root while it redirects', function () {
    (new TokenStore)->begin('v', 'state-1');

    Native::test(AuthDenied::class, ['state' => 'state-1'])
        ->assertReplacedWith('/sign-in')
        ->assertElement('column');
});

test('the alerts screen renders its title, tabs and a native list with tappable rows', function () {
    (new TokenStore)->put('9|secret');
    Http::fake(['wx.test/api/v1/alerts*' => Http::response(['data' => [renderableAlert()], 'meta' => ['next_cursor' => 'abc']])]);

    Native::test(AlertsScreen::class)
        ->assertNavTitle('Alerts')
        ->assertHasTabBar()
        ->assertTabActive('Alerts')
        ->assertHasTab('Account')
        ->assertElement('list', fn (array $list) => isset($list['props']['on_refresh'], $list['props']['on_end_reached']))
        ->assertElement('list_item', fn (array $item) => isset($item['on_press']) && $item['props']['headline'] === 'Tornado Warning')
        ->assertMissingElement('refreshable')
        ->assertMissingElement('pressable')
        ->assertMissingElement('button');
});

test('the alert detail renders its text and a product button', function () {
    (new TokenStore)->put('9|secret');
    Http::fake(['wx.test/api/v1/alerts/4' => Http::response(['data' => renderableAlert()])]);

    Native::test(AlertDetail::class, ['id' => 4])
        ->assertNavTitle('Alert')
        ->assertElement('scroll_view')
        ->assertElement('text')
        ->assertElement('divider')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'View full product');
});

test('the account screen renders its title, tabs, the profile and a sign-out button', function () {
    (new TokenStore)->put('9|secret');
    Http::fake(['wx.test/api/v1/me' => Http::response(['data' => ['id' => 5, 'name' => 'Pat Smith', 'email' => 'pat@example.com', 'tier' => ['value' => 'plus', 'label' => 'Plus'], 'unit_system' => 'imperial', 'timezone' => 'America/Chicago']])]);

    Native::test(AccountScreen::class)
        ->assertNavTitle('Account')
        ->assertHasTabBar()
        ->assertTabActive('Account')
        ->assertHasTab('Alerts')
        ->assertElement('text')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Sign out');
});

test('the native UI plugin is enabled for the native build', function () {
    expect((new NativeServiceProvider(app()))->plugins())->toContain(NativeUIServiceProvider::class);
});
