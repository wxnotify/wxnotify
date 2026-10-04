<?php

use App\Support\Pipeline\DockerEngineClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

test('status returns the container state when the container exists', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => ['Status' => 'running']], 200),
    ]);

    expect((new DockerEngineClient)->status('wxnotify-nwws'))->toBe('running');
});

test('status returns not_found when the container does not exist', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response([], 404),
    ]);

    expect((new DockerEngineClient)->status('wxnotify-nwws'))->toBe('not_found');
});

test('status throws when the docker daemon returns an unexpected error', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response([], 500),
    ]);

    (new DockerEngineClient)->status('wxnotify-nwws');
})->throws(RequestException::class);

test('start posts to the start endpoint', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/start' => Http::response('', 204),
    ]);

    (new DockerEngineClient)->start('wxnotify-nwws');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/containers/wxnotify-nwws/start'));
});

test('stop posts to the stop endpoint', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/stop' => Http::response('', 204),
    ]);

    (new DockerEngineClient)->stop('wxnotify-nwws');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/containers/wxnotify-nwws/stop'));
});

test('restart posts to the restart endpoint', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/restart' => Http::response('', 204),
    ]);

    (new DockerEngineClient)->restart('wxnotify-nwws');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/containers/wxnotify-nwws/restart'));
});

test('start throws when the docker daemon returns an error', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/start' => Http::response([], 500),
    ]);

    (new DockerEngineClient)->start('wxnotify-nwws');
})->throws(RequestException::class);

test('status throws a clear error when the docker response is missing a usable status', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/json' => Http::response(['State' => []], 200),
    ]);

    (new DockerEngineClient)->status('wxnotify-nwws');
})->throws(RuntimeException::class);

test('stop throws when the docker daemon returns an error', function () {
    Http::fake([
        'docker-socket-proxy:2375/v1.43/containers/wxnotify-nwws/stop' => Http::response([], 500),
    ]);

    (new DockerEngineClient)->stop('wxnotify-nwws');
})->throws(RequestException::class);

function dockerLogFrame(int $stream, string $payload): string
{
    return pack('CxxxN', $stream, strlen($payload)).$payload;
}

test('state reports the docker health status when a healthcheck exists', function () {
    Http::fake(['*/containers/c1/json' => Http::response(['State' => ['Status' => 'running', 'Health' => ['Status' => 'unhealthy']]])]);

    expect(app(DockerEngineClient::class)->state('c1'))->toBe(['status' => 'running', 'health' => 'unhealthy']);
});

test('state has a null health when the container defines no healthcheck', function () {
    Http::fake(['*/containers/c1/json' => Http::response(['State' => ['Status' => 'exited']])]);

    expect(app(DockerEngineClient::class)->state('c1'))->toBe(['status' => 'exited', 'health' => null]);
});

test('state reports not_found for a missing container and status still works', function () {
    Http::fake(['*/containers/c1/json' => Http::response([], 404)]);

    expect(app(DockerEngineClient::class)->state('c1'))->toBe(['status' => 'not_found', 'health' => null])
        ->and(app(DockerEngineClient::class)->status('c1'))->toBe('not_found');
});

test('logs demultiplexes the docker stream frames into lines', function () {
    $body = dockerLogFrame(1, "first line\nsecond line\n").dockerLogFrame(2, "error line\n");
    Http::fake(['*/containers/c1/logs*' => Http::response($body)]);

    expect(app(DockerEngineClient::class)->logs('c1'))->toBe(['first line', 'second line', 'error line']);
});

test('logs accepts a raw stream from a container running a tty', function () {
    Http::fake(['*/containers/c1/logs*' => Http::response("alpha\nbeta\n")]);

    expect(app(DockerEngineClient::class)->logs('c1'))->toBe(['alpha', 'beta']);
});

test('logs strips ansi sequences and control characters and truncates long lines', function () {
    $long = str_repeat('x', 600);
    $body = dockerLogFrame(1, "\e[31mred\e[0m\x07bell\n{$long}\n");
    Http::fake(['*/containers/c1/logs*' => Http::response($body)]);

    $lines = app(DockerEngineClient::class)->logs('c1');

    expect($lines[0])->toBe('redbell')
        ->and(mb_strlen($lines[1]))->toBe(501)
        ->and($lines[1])->toEndWith('…');
});

test('logs keeps only the last requested lines', function () {
    $body = dockerLogFrame(1, implode("\n", range(1, 10))."\n");
    Http::fake(['*/containers/c1/logs*' => Http::response($body)]);

    expect(app(DockerEngineClient::class)->logs('c1', 3))->toBe(['8', '9', '10']);
});

test('logs clamps a non-positive tail to a single line instead of returning everything', function () {
    $body = dockerLogFrame(1, implode("\n", range(1, 10))."\n");
    Http::fake(['*/containers/c1/logs*' => Http::response($body)]);

    expect(app(DockerEngineClient::class)->logs('c1', 0))->toBe(['10']);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'tail=1'));
});

test('logs returns an empty list for a missing container and throws on other errors', function () {
    Http::fake(['*/containers/gone/logs*' => Http::response('', 404), '*/containers/bad/logs*' => Http::response('', 500)]);

    expect(app(DockerEngineClient::class)->logs('gone'))->toBe([]);
    expect(fn () => app(DockerEngineClient::class)->logs('bad'))->toThrow(RequestException::class);
});
