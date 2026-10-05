<?php

use App\Support\Delivery\FcmClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->credentials = fcmUseCredentials();
    Cache::flush();
});

afterEach(function () {
    @unlink($this->credentials['path']);
});

test('a message goes to the FCM v1 API with a bearer token and the alert as a notification', function () {
    fcmFakeGoogle();

    $result = app(FcmClient::class)->send('device-token-1', 'SPC Day 1', 'Slight risk of severe storms', ['url' => '/']);

    expect($result->success)->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://fcm.googleapis.com/v1/projects/wx-test/messages:send'
        && $request->hasHeader('Authorization', 'Bearer ya29.test-access-token')
        && $request['message']['token'] === 'device-token-1'
        && $request['message']['notification'] === ['title' => 'SPC Day 1', 'body' => 'Slight risk of severe storms']
        && (array) $request['message']['data'] === ['url' => '/']
        && $request['message']['android']['priority'] === 'HIGH'
        && $request['message']['apns']['payload']['aps']['sound'] === 'default'
        && ! isset($request['message']['android']['notification']['channel_id']));
});

test('the access token is requested with a JWT signed by the service account key', function () {
    fcmFakeGoogle();

    app(FcmClient::class)->send('t', 'a', 'b');

    [$request] = Http::recorded(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/token')->first();
    [$header, $claims, $signature] = explode('.', $request['assertion']);
    $decode = fn (string $segment) => base64_decode(strtr($segment, '-_', '+/'));
    $claimData = json_decode($decode($claims), true);

    expect($request['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:jwt-bearer')
        ->and(json_decode($decode($header), true))->toBe(['alg' => 'RS256', 'typ' => 'JWT'])
        ->and(openssl_verify("{$header}.{$claims}", $decode($signature), $this->credentials['public'], OPENSSL_ALGO_SHA256))->toBe(1)
        ->and($claimData['iss'])->toBe('push@wx-test.iam.gserviceaccount.com')
        ->and($claimData['scope'])->toBe('https://www.googleapis.com/auth/firebase.messaging')
        ->and($claimData['aud'])->toBe('https://oauth2.googleapis.com/token')
        ->and($claimData['exp'] - $claimData['iat'])->toBe(3600);
});

test('the access token is cached across sends', function () {
    fcmFakeGoogle();
    $client = app(FcmClient::class);

    $client->send('device-a', 't', 'b');
    $client->send('device-b', 't', 'b');

    expect(Http::recorded(fn (Request $request) => str_contains($request->url(), 'oauth2.googleapis.com')))->toHaveCount(1)
        ->and(Http::recorded(fn (Request $request) => str_contains($request->url(), 'fcm.googleapis.com')))->toHaveCount(2);
});

test('without usable credentials nothing is sent and the failure says push is not configured', function (string $break) {
    $path = $this->credentials['path'];
    match ($break) {
        'no setting' => config(['services.firebase.service_account' => null]),
        'missing file' => config(['services.firebase.service_account' => $path.'.missing']),
        'not json' => file_put_contents($path, 'nope'),
        'no private key' => file_put_contents($path, json_encode(['project_id' => 'x', 'client_email' => 'a@b.c'])),
    };
    Http::fake();

    $result = app(FcmClient::class)->send('t', 'a', 'b');

    expect($result->success)->toBeFalse()->and($result->error)->toBe('Push is not configured on this server.');
    Http::assertNothingSent();
})->with(['no setting', 'missing file', 'not json', 'no private key']);

test('FCM errors are classified and the failure text is fixed', function (int $status, array $body, bool $tokenInvalid, string $error) {
    fcmFakeGoogle(Http::response($body, $status));

    $result = app(FcmClient::class)->send('super-secret-device-token', 'a', 'b');

    expect($result->success)->toBeFalse()
        ->and($result->tokenInvalid)->toBe($tokenInvalid)
        ->and($result->error)->toBe($error)
        ->and($result->error)->not->toContain('super-secret-device-token');
})->with([
    'unregistered' => [404, ['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found. super-secret-device-token', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]], true, 'This device is no longer registered for push.'],
    'invalid token' => [400, ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'The registration token is not a valid FCM registration token']], true, 'This device is no longer registered for push.'],
    'another invalid argument' => [400, ['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'Request contains an invalid argument.']], false, 'Firebase rejected the message (HTTP 400).'],
    'a 404 that is not the token' => [404, ['error' => ['status' => 'NOT_FOUND', 'message' => 'Project not found']], false, 'Firebase rejected the message (HTTP 404).'],
    'quota' => [429, ['error' => ['status' => 'RESOURCE_EXHAUSTED']], false, 'Firebase rejected the message (HTTP 429).'],
    'server error' => [503, [], false, 'Firebase rejected the message (HTTP 503).'],
]);

test('a rejected access token is forgotten so the next send asks Google again', function () {
    fcmFakeGoogle(Http::response(['error' => ['status' => 'UNAUTHENTICATED']], 401));
    $cacheKey = 'fcm.access_token.'.sha1('push@wx-test.iam.gserviceaccount.com');

    $result = app(FcmClient::class)->send('t', 'a', 'b');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('Firebase rejected the server credentials (HTTP 401).')
        ->and(Cache::has($cacheKey))->toBeFalse();
});

test('a failed token exchange stops before the send', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    $result = app(FcmClient::class)->send('t', 'a', 'b');

    expect($result->success)->toBeFalse()->and($result->error)->toBe('Could not get a Firebase access token.');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'fcm.googleapis.com'));
});

test('an unreachable Firebase fails with a fixed message that carries nothing from the exception', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.x', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => fn () => throw new ConnectionException('cURL error: secret-host-detail'),
    ]);

    $result = app(FcmClient::class)->send('t', 'a', 'b');

    expect($result->success)->toBeFalse()->and($result->error)->toBe('Firebase could not be reached.');
});
