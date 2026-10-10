<?php

use App\NativeComponents\SettingsScreen;
use App\Support\TokenStore;
use Native\Mobile\Testing\Native;

test('Settings lists rules, locations and account under the active Settings tab', function () {
    (new TokenStore)->put('9|secret');

    Native::test(SettingsScreen::class)
        ->assertNavTitle('Settings')
        ->assertHasTab('Alerts')
        ->assertTabActive('Settings')
        ->assertSee('Alert rules')
        ->assertSee('Locations')
        ->assertSee('Account');
});

test('each row pushes its page so the back arrow returns to Settings', function (string $method, string $uri) {
    (new TokenStore)->put('9|secret');

    Native::test(SettingsScreen::class)->press($method)->assertNavigatedTo($uri);
})->with([
    ['openRules', '/rules'],
    ['openLocations', '/locations'],
    ['openAccount', '/account'],
]);

test('a signed-out visitor is sent to Sign in', function () {
    Native::test(SettingsScreen::class)->assertReplacedWith('/sign-in');
});
