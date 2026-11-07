<?php

use Illuminate\Support\Facades\Route;

test('the reverb connection is configured from the environment with the keys the browser needs', function () {
    $connection = config('broadcasting.connections.reverb');

    expect($connection)->toBeArray()
        ->and($connection['driver'])->toBe('reverb')
        ->and($connection)->toHaveKeys(['key', 'secret', 'app_id'])
        ->and($connection['options'])->toHaveKeys(['host', 'port', 'scheme']);
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
