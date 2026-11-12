<?php

use App\Support\ForceHttpsFromAppUrl;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Route;

/**
 * Registers a route that reports the URLs the app generates for the current request, then
 * requests it with the given full URL (the scheme the app sees after a TLS proxy hop).
 *
 * @return array{url: string, asset: string, route: string}
 */
function generatedUrlsFor(string $requestUrl): array
{
    Route::get('/__scheme-probe', fn (): array => [
        'url' => url('/dashboard'),
        'asset' => asset('css/app.css'),
        'route' => route('filament.dashboard.auth.login'),
    ]);

    return test()->get($requestUrl)->assertOk()->json();
}

test('an https APP_URL makes url, asset and route https for a request that reached the app over plain http', function () {
    config(['app.url' => 'https://wx.example.test']);
    app(ForceHttpsFromAppUrl::class)();

    $urls = generatedUrlsFor('http://wx.example.test/__scheme-probe');

    expect($urls['url'])->toBe('https://wx.example.test/dashboard')
        ->and($urls['asset'])->toBe('https://wx.example.test/css/app.css')
        ->and($urls['route'])->toBe('https://wx.example.test/dashboard/login');
});

test('an http APP_URL leaves the scheme to the request', function () {
    config(['app.url' => 'http://wx.example.test']);
    app(ForceHttpsFromAppUrl::class)();

    expect(generatedUrlsFor('http://wx.example.test/__scheme-probe'))->each->toStartWith('http://wx.example.test/')
        ->and(generatedUrlsFor('https://wx.example.test/__scheme-probe'))->each->toStartWith('https://wx.example.test/');
});

test('the app forces https at boot when APP_URL is https', function () {
    $saved = [$_SERVER['APP_URL'] ?? null, $_ENV['APP_URL'] ?? null];
    $restore = function () use ($saved): void {
        unset($_SERVER['APP_URL'], $_ENV['APP_URL']);

        if ($saved[0] !== null) {
            $_SERVER['APP_URL'] = $saved[0];
        }

        if ($saved[1] !== null) {
            $_ENV['APP_URL'] = $saved[1];
        }

        Env::enablePutenv();
    };

    // A fresh env repository forgets that .env loaded APP_URL, so the reboot keeps this value
    // instead of reloading the file's.
    Env::enablePutenv();
    $_SERVER['APP_URL'] = $_ENV['APP_URL'] = 'https://wx.example.test';

    try {
        $this->refreshApplication();

        expect(config('app.url'))->toBe('https://wx.example.test')
            ->and(generatedUrlsFor('http://wx.example.test/__scheme-probe'))->each->toStartWith('https://wx.example.test/');
    } finally {
        $restore();
    }
});
