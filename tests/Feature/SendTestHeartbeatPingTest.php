<?php

use App\Actions\Pipeline\SendTestHeartbeatPing;
use App\Support\Delivery\HeartbeatUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();
});

function testPing(string $url, array $ips = ['203.0.113.10']): array
{
    return (new SendTestHeartbeatPing(new HeartbeatUrlGuard(fn (string $host): array => $ips)))($url);
}

test('a 2xx answer is a success naming the status', function () {
    Http::fake(['hc.example.test/*' => Http::response('OK', 200)]);

    expect(testPing('https://hc.example.test/ping/secret-uuid'))->toBe(['ok' => true, 'message' => 'The server answered HTTP 200.']);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://hc.example.test/ping/secret-uuid');
});

test('a non-2xx answer is a failure naming the status', function (int $status) {
    Http::fake(['hc.example.test/*' => Http::response('no', $status)]);

    expect(testPing('https://hc.example.test/ping/x'))->toBe(['ok' => false, 'message' => "The server answered HTTP {$status}."]);
})->with([404, 500, 302]);

test('a transport error is a failure that never repeats the exception text or URL', function () {
    Http::fake(['hc.example.test/*' => fn () => throw new ConnectionException('cURL error 7: https://hc.example.test/ping/secret-uuid')]);

    $result = testPing('https://hc.example.test/ping/secret-uuid');

    expect($result)->toBe(['ok' => false, 'message' => 'Could not reach the server.']);
});

test('a redirect is not followed', function () {
    Http::fake([
        'hc.example.test/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest']),
        '169.254.169.254/*' => Http::response('metadata'),
    ]);

    expect(testPing('https://hc.example.test/ping/x')['ok'])->toBeFalse();
    Http::assertSentCount(1);
});

test('the ping is pinned to the addresses the guard vetted', function () {
    $sent = null;
    Http::fake(['hc.example.test/*' => function ($request, array $options) use (&$sent) {
        $sent = $options;

        return Http::response('OK');
    }]);

    testPing('https://hc.example.test/ping/x');

    expect($sent['curl'][CURLOPT_RESOLVE])->toBe(['hc.example.test:443:203.0.113.10'])
        ->and($sent['allow_redirects'])->toBeFalse();
});

test('a URL the guard refuses is not requested and gets the guard message', function (string $url, array $ips, string $message) {
    Http::fake();

    expect(testPing($url, $ips))->toBe(['ok' => false, 'message' => $message]);
    Http::assertNothingSent();
})->with([
    'metadata address' => ['http://169.254.169.254/ping/x', [], 'That address is a link-local or cloud metadata address.'],
    'resolves to metadata' => ['https://hc.example.test/ping/x', ['169.254.169.254'], 'That address is a link-local or cloud metadata address.'],
    'unresolvable' => ['https://hc.example.test/ping/x', [], 'That host name could not be resolved.'],
    'non-ASCII host' => ['http://169。254。169。254/ping/x', [], 'Enter the server host name in plain ASCII (use the xn-- form for international names).'],
]);

test('a stale cached refusal does not block a retest after the host starts resolving', function () {
    Http::fake(['hc.example.test/*' => Http::response('OK')]);

    expect(testPing('https://hc.example.test/ping/x', [])['ok'])->toBeFalse();

    $guard = new HeartbeatUrlGuard(fn (string $host): array => ['203.0.113.10']);

    expect((new SendTestHeartbeatPing($guard))('https://hc.example.test/ping/x')['ok'])->toBeTrue();
});

test('nothing about the URL is logged', function () {
    Http::fake(['hc.example.test/*' => Http::response('no', 500)]);
    Log::spy();

    testPing('https://hc.example.test/ping/secret-uuid');

    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});
