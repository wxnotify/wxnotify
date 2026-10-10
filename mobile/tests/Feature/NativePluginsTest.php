<?php

use App\Providers\NativeServiceProvider;
use Native\Mobile\Providers\BrowserServiceProvider;
use Native\Mobile\Providers\GeolocationServiceProvider;
use Native\Mobile\Providers\NetworkServiceProvider;
use Native\Mobile\Providers\PushNotificationsServiceProvider;
use Native\Mobile\Providers\SecureStorageServiceProvider;

/*
 * The in-process test harness fakes every native call, so a missing plugin is invisible to the
 * rest of the suite: the app builds and runs, and the call quietly fails on the device (the
 * browser sign-in returned false until its plugin was installed). Each native capability the
 * app code calls is listed here with the plugin that provides its native half.
 */
test('every native capability the app calls has its plugin registered for the native build', function (string $provider) {
    expect((new NativeServiceProvider(app()))->plugins())->toContain($provider);
})->with([
    'Browser::auth() and Browser::inApp() (sign-in handoff, View full product)' => BrowserServiceProvider::class,
    'Geolocation::sharePosition() and backgroundWatchStatus() (the location beacon), getCurrentPosition() and requestPermissions() (the location editor)' => GeolocationServiceProvider::class,
    'Network::status() (offline detection)' => NetworkServiceProvider::class,
    'PushNotifications (permission, enrollment and the FCM token)' => PushNotificationsServiceProvider::class,
    'SecureStorage (the API token)' => SecureStorageServiceProvider::class,
]);
