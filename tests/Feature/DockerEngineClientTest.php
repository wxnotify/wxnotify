<?php

use App\Support\Pipeline\DockerEngineClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

test('status returns the container state when the container exists', function () {
    Http::fake([
        'localhost/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
    ]);

    expect((new DockerEngineClient)->status('wxnotify-nwws'))->toBe('running');
});

test('status returns not_found when the container does not exist', function () {
    Http::fake([
        'localhost/v1.43/containers/wxnotify-nwws/json' => Http::response([], 404),
    ]);

    expect((new DockerEngineClient)->status('wxnotify-nwws'))->toBe('not_found');
});

test('status throws when the docker daemon returns an unexpected error', function () {
    Http::fake([
        'localhost/v1.43/containers/wxnotify-nwws/json' => Http::response([], 500),
    ]);

    (new DockerEngineClient)->status('wxnotify-nwws');
})->throws(RequestException::class);

test('start posts to the start endpoint', function () {
    Http::fake([
        'localhost/v1.43/containers/wxnotify-nwws/start' => Http::response('', 204),
    ]);

    (new DockerEngineClient)->start('wxnotify-nwws');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/containers/wxnotify-nwws/start'));
});

test('stop posts to the stop endpoint', function () {
    Http::fake([
        'localhost/v1.43/containers/wxnotify-nwws/stop' => Http::response('', 204),
    ]);

    (new DockerEngineClient)->stop('wxnotify-nwws');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/containers/wxnotify-nwws/stop'));
});

test('restart posts to the restart endpoint', function () {
    Http::fake([
        'localhost/v1.43/containers/wxnotify-nwws/restart' => Http::response('', 204),
    ]);

    (new DockerEngineClient)->restart('wxnotify-nwws');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/containers/wxnotify-nwws/restart'));
});

test('start throws when the docker daemon returns an error', function () {
    Http::fake([
        'localhost/v1.43/containers/wxnotify-nwws/start' => Http::response([], 500),
    ]);

    (new DockerEngineClient)->start('wxnotify-nwws');
})->throws(RequestException::class);
