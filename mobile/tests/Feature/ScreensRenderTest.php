<?php

use App\NativeComponents\AccountScreen;
use App\NativeComponents\AlertDetail;
use App\NativeComponents\AlertsScreen;
use App\NativeComponents\AuthCallback;
use App\NativeComponents\AuthDenied;
use App\NativeComponents\LocationEditor;
use App\NativeComponents\LocationPrompt;
use App\NativeComponents\LocationsScreen;
use App\NativeComponents\PushPrompt;
use App\NativeComponents\RuleEditor;
use App\NativeComponents\RulesScreen;
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
        ->assertHasTab('Map')
        ->assertHasTab('Settings')
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
        ->assertTabActive('Settings')
        ->assertHasTab('Alerts')
        ->assertHasTab('Map')
        ->assertElement('text')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Sign out');
});

test('the rules screen renders its title, tabs, an add button and a tappable row with a switch', function () {
    (new TokenStore)->put('9|secret');
    Http::fake(['wx.test/api/v1/alert-rules' => Http::response(['data' => [['id' => 1, 'name' => 'Freeze', 'display_name' => 'Freeze', 'category' => 'threshold', 'category_label' => 'Threshold Alerts', 'is_active' => true]]])]);

    Native::test(RulesScreen::class)
        ->assertNavTitle('Alert rules')
        ->assertHasTabBar()
        ->assertTabActive('Settings')
        ->assertHasTab('Alerts')
        ->assertHasTab('Map')
        ->assertElement('scroll_view')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Add rule')
        ->assertElement('pressable')
        ->assertElement('toggle', fn (array $toggle) => $toggle['props']['value'] === true);
});

test('the rule editor opens from both routes and renders its form elements', function () {
    (new TokenStore)->put('9|secret');
    Http::fake([
        'wx.test/api/v1/alert-rule-options' => Http::response(['data' => ruleFormOptions()]),
        'wx.test/api/v1/alert-rules/4' => Http::response(['data' => [
            'id' => 4, 'name' => null, 'category' => 'weather_alerts', 'matching_mode' => 'location', 'product_ids' => [10], 'location_ids' => [7],
            'location_id' => null, 'wfo' => null, 'county_zone_codes' => [], 'risk_levels' => [], 'channel_ids' => [5],
        ]]),
    ]);

    Native::visit('/rules/new')
        ->assertScreen(RuleEditor::class)
        ->assertNavTitle('New alert rule')
        ->assertSet('ruleId', null)
        ->assertElement('pressable')
        ->assertElement('checkbox')
        ->tap('category-threshold')
        ->assertElement('select')
        ->assertElement('outlined_text_input')
        ->assertElement('date_picker', fn (array $picker) => $picker['props']['mode'] === 'time');

    Native::visit('/rules/4')
        ->assertNavTitle('Edit alert rule')
        ->assertSet('ruleId', 4)
        ->assertElement('accordion', fn (array $accordion) => $accordion['props']['expanded'] === true)
        ->assertElement('checkbox', fn (array $checkbox) => $checkbox['props']['value'] === true)
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Delete rule');
});

test('the locations screen renders its title, tabs, an add button and a tappable row', function () {
    (new TokenStore)->put('9|secret');
    Http::fake(['wx.test/api/v1/locations' => Http::response([
        'data' => [['id' => 3, 'label' => 'Home', 'type' => 'stationary', 'source' => null, 'address' => '1 Main Street, Norman', 'lat' => 35.2226, 'lng' => -97.4395, 'timezone' => 'America/Chicago']],
        'meta' => ['can_create' => true, 'limit' => 5],
    ])]);

    Native::test(LocationsScreen::class)
        ->assertNavTitle('Locations')
        ->assertHasTabBar()
        ->assertTabActive('Settings')
        ->assertHasTab('Alerts')
        ->assertHasTab('Map')
        ->assertElement('scroll_view')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Add location')
        ->assertElement('pressable')
        ->assertElement('divider');
});

test('the location editor renders the stationary form', function () {
    (new TokenStore)->put('9|secret');
    Http::fake(['wx.test/api/v1/locations' => Http::response(['data' => [], 'meta' => ['can_create' => true, 'limit' => 5]])]);

    Native::test(LocationEditor::class)
        ->assertNavTitle('New location')
        ->assertElement('scroll_view')
        ->assertElement('outlined_text_input')
        ->assertElement('select', fn (array $select) => $select['props']['label'] === 'Timezone')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Use my current position')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Search')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Save location')
        ->assertMissingElement('select', fn (array $select) => $select['props']['label'] === 'Update every');
});

test('the location editor renders a phone location with an update interval and a delete button, and no position search', function () {
    (new TokenStore)->put('9|secret');
    Http::fake(['wx.test/api/v1/locations' => Http::response(['data' => [[
        'id' => 2, 'label' => 'Pixel 9', 'type' => 'mobile', 'source' => 'app', 'source_label' => 'WxNotify app', 'address' => null, 'lat' => 35.2, 'lng' => -97.4,
        'timezone' => null, 'update_interval_minutes' => 5, 'linked' => true, 'device_name' => null, 'has_missing_device' => false,
    ]], 'meta' => ['can_create' => true, 'limit' => 5]])]);

    Native::test(LocationEditor::class, ['id' => 2])
        ->assertNavTitle('Edit location')
        ->assertElement('outlined_text_input')
        ->assertElement('select', fn (array $select) => $select['props']['label'] === 'Update every')
        ->assertElement('button', fn (array $button) => $button['props']['label'] === 'Delete location')
        ->assertMissingElement('select', fn (array $select) => $select['props']['label'] === 'Timezone')
        ->assertMissingElement('button', fn (array $button) => $button['props']['label'] === 'Use my current position');
});

test('the native UI plugin is enabled for the native build', function () {
    expect((new NativeServiceProvider(app()))->plugins())->toContain(NativeUIServiceProvider::class);
});
