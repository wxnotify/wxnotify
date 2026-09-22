<?php

use App\Actions\Pipeline\DispatchHeartbeatPing;
use App\Jobs\SendHeartbeatPing;
use App\Settings\IntegrationSettings;
use App\Support\Delivery\HeartbeatUrlGuard;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function heartbeatSetup(array $overrides = []): void
{
    $settings = app(IntegrationSettings::class);
    $settings->heartbeat_enabled = true;

    foreach (['heartbeat_nwws_url' => 'https://hc.example.test/ping/nwws-uuid', 'heartbeat_emwin_url' => 'https://hc.example.test/ping/emwin-uuid', 'heartbeat_emwin_http_url' => 'https://hc.example.test/ping/http-uuid', ...$overrides] as $property => $value) {
        $settings->{$property} = $value;
    }

    $settings->save();
}

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();
});

// --- guard ---

test('the guard allows public https and private http hosts', function (string $url) {
    $guard = new HeartbeatUrlGuard(fn (string $host): array => ['10.0.0.5']);

    expect($guard->violation($url))->toBeNull();
})->with([
    'public https' => 'https://hc.example.test/ping/abc',
    'private http host' => 'http://healthchecks.internal:8000/ping/abc',
    'private ip' => 'http://192.168.1.20/ping/abc',
    'loopback' => 'http://127.0.0.1:8000/ping/abc',
    'ipv6 site-local is not link-local' => 'http://[fec0::1]/ping',
]);

test('the guard refuses other schemes, embedded credentials and unparseable urls', function (string $url) {
    expect((new HeartbeatUrlGuard(fn (string $host): array => ['203.0.113.10']))->violation($url))->not->toBeNull();
})->with([
    'ftp' => 'ftp://hc.example.test/ping/abc',
    'file' => 'file:///etc/passwd',
    'credentials' => 'https://user:pass@hc.example.test/ping/abc',
    'password only' => 'https://:pass@hc.example.test/ping/abc',
    'no scheme' => 'hc.example.test/ping/abc',
    'no host' => 'http:///ping/abc',
]);

test('the guard refuses link-local and cloud metadata targets', function (string $url) {
    expect((new HeartbeatUrlGuard(fn (string $host): array => []))->violation($url))->not->toBeNull();
})->with([
    'aws/azure/gcp ipv4' => 'http://169.254.169.254/latest/meta-data',
    'link-local range' => 'http://169.254.10.20/ping',
    'gcp name' => 'http://metadata.google.internal/computeMetadata/v1/',
    'ipv6 link-local' => 'http://[fe80::1]/ping',
    'aws ipv6 imds' => 'http://[fd00:ec2::254]/latest',
    'ipv4-mapped metadata' => 'http://[::ffff:169.254.169.254]/latest',
    'decimal ipv4' => 'http://2852039166/',
    'hex ipv4' => 'http://0xA9FEA9FE/',
    'octal dotted ipv4' => 'http://0251.0376.0251.0376/',
    'ipv6 zone id' => 'http://[fe80::1%25eth0]/ping',
    'ipv6 link-local febf' => 'http://[febf::1]/ping',
    'nat64' => 'http://[64:ff9b::a9fe:a9fe]/ping',
    '6to4' => 'http://[2002:a9fe:a9fe::]/ping',
    'ipv4-translated hex form' => 'http://[::ffff:0:7f00:1]/ping',
    'trailing dot host' => 'http://169.254.169.254./',
]);

test('the guard refuses a host name that resolves to a metadata address', function () {
    $guard = new HeartbeatUrlGuard(fn (string $host): array => ['169.254.169.254']);

    expect($guard->violation('https://looks-harmless.example.test/ping'))->not->toBeNull();
});

test('the guard resolves each host at most once within the cache window', function () {
    $calls = 0;
    $guard = new HeartbeatUrlGuard(function (string $host) use (&$calls): array {
        $calls++;

        return ['10.0.0.5'];
    });

    $guard->violation('http://healthchecks.internal:8000/ping/abc');
    $guard->violation('http://healthchecks.internal:8000/ping/abc');

    expect($calls)->toBe(1);
});

// --- job ---

test('the job sends one GET to the feed url and nothing else', function () {
    heartbeatSetup();
    Http::fake(['hc.example.test/*' => Http::response('OK', 200)]);

    (new SendHeartbeatPing('emwin'))->handle(new HeartbeatUrlGuard(fn (string $host): array => ['203.0.113.10']));

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://hc.example.test/ping/emwin-uuid');
});

test('the job reads the url when it runs, so a url removed after queueing is not called', function () {
    heartbeatSetup();
    $job = new SendHeartbeatPing('nwws');
    heartbeatSetup(['heartbeat_nwws_url' => null]);

    $job->handle(new HeartbeatUrlGuard(fn (string $host): array => ['203.0.113.10']));

    Http::assertNothingSent();
});

test('the job does nothing for an unknown feed or when heartbeat is off', function () {
    heartbeatSetup();
    (new SendHeartbeatPing('bogus'))->handle(new HeartbeatUrlGuard(fn (string $host): array => ['203.0.113.10']));

    $settings = app(IntegrationSettings::class);
    $settings->heartbeat_enabled = false;
    $settings->save();
    (new SendHeartbeatPing('nwws'))->handle(new HeartbeatUrlGuard(fn (string $host): array => ['203.0.113.10']));

    Http::assertNothingSent();
});

test('the job does not call a url the guard refuses and logs only the feed', function () {
    heartbeatSetup(['heartbeat_nwws_url' => 'http://169.254.169.254/latest/nwws-uuid']);
    Http::fake(['*' => Http::response('OK', 200)]);
    Log::spy();

    (new SendHeartbeatPing('nwws'))->handle(new HeartbeatUrlGuard(fn (string $host): array => []));

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context = []) => $context === ['feed' => 'nwws']);
});

test('a non-2xx response or a transport error is swallowed and logged without the url', function (string $case) {
    heartbeatSetup();
    Log::spy();
    Http::fake(['hc.example.test/*' => match ($case) {
        'server error' => Http::response('nope', 500),
        'redirect' => Http::response('', 302, ['Location' => 'http://169.254.169.254/']),
        'transport error' => fn () => throw new ConnectionException('cURL error 7 https://hc.example.test/ping/http-uuid'),
    }]);

    (new SendHeartbeatPing('emwin_http'))->handle(new HeartbeatUrlGuard(fn (string $host): array => ['203.0.113.10']));

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context = []) {
        return $context === ['feed' => 'emwin_http'] && ! str_contains($message.json_encode($context), 'http-uuid');
    });
    Log::shouldNotHaveReceived('error');
})->with(['server error', 'redirect', 'transport error']);

test('the failure warning is throttled per feed and the throttle cannot throw', function () {
    heartbeatSetup();
    Log::spy();
    Http::fake(['hc.example.test/*' => Http::response('nope', 500)]);
    $guard = new HeartbeatUrlGuard(fn (string $host): array => ['203.0.113.10']);

    (new SendHeartbeatPing('nwws'))->handle($guard);
    (new SendHeartbeatPing('nwws'))->handle($guard);

    Log::shouldHaveReceived('warning')->once();
});

test('the job builds its request with redirects disabled and a short timeout', function () {
    $job = new SendHeartbeatPing('nwws');
    $request = (function (): PendingRequest {
        return $this->pendingRequest();
    })->call($job);

    $options = (new ReflectionProperty(PendingRequest::class, 'options'))->getValue($request);

    expect($options['allow_redirects'])->toBeFalse()
        ->and($options['timeout'])->toBe(5);
});

test('the job runs on its own queue with a job-level timeout backstop', function () {
    $job = new SendHeartbeatPing('nwws');

    expect($job->queue)->toBe('heartbeat')
        ->and($job->timeout)->toBe(15);
});

// --- dispatch action ---

test('the dispatch action queues the job only when the feed has a url and heartbeat is on', function () {
    Bus::fake();

    (app(DispatchHeartbeatPing::class))('nwws');
    Bus::assertNothingDispatched();

    heartbeatSetup();
    (app(DispatchHeartbeatPing::class))('nwws');
    (app(DispatchHeartbeatPing::class))('emwin_http');

    Bus::assertDispatchedTimes(SendHeartbeatPing::class, 2);
    Bus::assertDispatched(SendHeartbeatPing::class, fn (SendHeartbeatPing $job) => $job->feed === 'nwws');
    Bus::assertDispatched(SendHeartbeatPing::class, fn (SendHeartbeatPing $job) => $job->feed === 'emwin_http');
});

test('the dispatch action ignores a blank url and an unknown feed', function () {
    Bus::fake();
    heartbeatSetup(['heartbeat_emwin_url' => null]);

    (app(DispatchHeartbeatPing::class))('emwin');
    (app(DispatchHeartbeatPing::class))('bogus');

    Bus::assertNothingDispatched();
});

test('the dispatch action never throws when queueing fails', function () {
    heartbeatSetup();
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue down'));
    $this->instance(Dispatcher::class, $dispatcher);

    expect(fn () => (app(DispatchHeartbeatPing::class))('nwws'))->not->toThrow(Throwable::class);
});

test('the dispatch action logs only the feed, throttled, when queueing fails', function () {
    heartbeatSetup();
    Log::spy();
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->twice()->andThrow(new RuntimeException('queue down'));
    $this->instance(Dispatcher::class, $dispatcher);

    (app(DispatchHeartbeatPing::class))('nwws');
    (app(DispatchHeartbeatPing::class))('nwws');

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context = []) => $context === ['feed' => 'nwws']);
});

test('the dispatch action still returns normally when the failure-warning log itself throws', function () {
    heartbeatSetup();
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue down'));
    $this->instance(Dispatcher::class, $dispatcher);
    Log::shouldReceive('warning')->andThrow(new RuntimeException('log down'));

    expect(fn () => (app(DispatchHeartbeatPing::class))('nwws'))->not->toThrow(Throwable::class);
});

test('the dispatch action queues nothing while heartbeat is off', function () {
    Bus::fake();
    heartbeatSetup();
    $settings = app(IntegrationSettings::class);
    $settings->heartbeat_enabled = false;
    $settings->save();

    (app(DispatchHeartbeatPing::class))('nwws');

    Bus::assertNothingDispatched();
});

test('a guard that throws does not make the job throw and logs only the feed', function () {
    heartbeatSetup();
    Http::fake(['*' => Http::response('OK', 200)]);
    Log::spy();
    $guard = new HeartbeatUrlGuard(fn (string $host): array => throw new RuntimeException('boom https://hc.example.test/ping/nwws-uuid'));

    (new SendHeartbeatPing('nwws'))->handle($guard);

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context = []) => $context === ['feed' => 'nwws'] && ! str_contains($message, 'nwws-uuid'));
});

test('the real resolver treats an unresolvable host as having no addresses', function () {
    heartbeatSetup(['heartbeat_nwws_url' => 'https://hc-does-not-exist.invalid/ping/x']);
    Http::fake(['*' => Http::response('OK', 200)]);

    expect((new HeartbeatUrlGuard)->violation('https://hc-does-not-exist.invalid/ping/x'))->toBeNull();

    expect(fn () => (new SendHeartbeatPing('nwws'))->handle(new HeartbeatUrlGuard))->not->toThrow(Throwable::class);
});
