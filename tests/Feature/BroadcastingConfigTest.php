<?php

use Illuminate\Support\Facades\Route;

test('the reverb connection is configured from the environment with the keys the browser needs', function () {
    $connection = config('broadcasting.connections.reverb');

    expect($connection)->toBeArray()
        ->and($connection['driver'])->toBe('reverb')
        ->and($connection)->toHaveKeys(['key', 'secret', 'app_id'])
        ->and($connection['options'])->toHaveKeys(['host', 'port', 'scheme']);
});

test('the public reverb settings default to the published port over plain ws and use the app key', function () {
    $public = config('reverb.public');

    expect($public['key'])->toBe(config('reverb.apps.apps.0.key'))
        ->and($public['port'])->toBeInt()
        ->and($public['scheme'])->toBeIn(['http', 'https']);
});

test('the default broadcast connection under test is not reverb', function () {
    expect(config('broadcasting.default'))->not->toBe('reverb');
});

test('the broadcasting auth route uses the web session and refuses a guest', function () {
    $route = Route::getRoutes()->getByName('broadcasting.auth') ?? collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route) => $route->uri() === 'broadcasting/auth');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('web');

    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);

    $this->postJson('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-x'])
        ->assertForbidden();
});

test('reverb allowed origins default to the app host and never to a wildcard', function () {
    $saved = [$_SERVER['APP_URL'] ?? null, $_ENV['APP_URL'] ?? null];
    $allowedOrigins = function (string $appUrl, ?string $list): array {
        $_SERVER['APP_URL'] = $_ENV['APP_URL'] = $appUrl;
        unset($_SERVER['REVERB_ALLOWED_ORIGINS'], $_ENV['REVERB_ALLOWED_ORIGINS']);

        if ($list !== null) {
            $_SERVER['REVERB_ALLOWED_ORIGINS'] = $_ENV['REVERB_ALLOWED_ORIGINS'] = $list;
        }

        return (require config_path('reverb.php'))['apps']['apps'][0]['allowed_origins'];
    };

    try {
        expect($allowedOrigins('http://127.0.0.1:8000', null))->toBe(['127.0.0.1'])
            ->and($allowedOrigins('https://app.example.com', null))->toBe(['app.example.com'])
            ->and($allowedOrigins('http://127.0.0.1:8000', ' app.example.com , *.example.org ,'))->toBe(['app.example.com', '*.example.org']);
    } finally {
        unset($_SERVER['REVERB_ALLOWED_ORIGINS'], $_ENV['REVERB_ALLOWED_ORIGINS']);
        [$_SERVER['APP_URL'], $_ENV['APP_URL']] = $saved;
    }

    expect(config('reverb.apps.apps.0.allowed_origins'))->not->toContain('*');
});
