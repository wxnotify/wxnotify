<?php

use App\Exceptions\Api\Rejected;
use App\Exceptions\InvalidSignIn;
use App\Services\SignIn;
use App\Support\LocalCache;
use App\Support\Pkce;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    $this->signIn = app(SignIn::class);
    $this->tokens = new TokenStore;
});

test('beginning stores a pending sign-in and opens the authorize page with the PKCE challenge', function () {
    Native::fakeBridge()->respondTo('Device.GetInfo', ['info' => json_encode(['model' => 'iPhone 16'])]);

    expect($this->signIn->begin())->toBeTrue();

    $pending = $this->tokens->pending();
    parse_str((string) parse_url($this->browser->authUrls[0], PHP_URL_QUERY), $query);

    expect($this->browser->authUrls[0])->toStartWith('https://wx.test/app-auth/authorize?')
        ->and($pending['verifier'])->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($query['challenge'])->toBe(Pkce::challengeFor($pending['verifier']))
        ->and($query['state'])->toBe($pending['state'])
        ->and($query['redirect'])->toBe('wxnotify://auth/callback')
        ->and($query['device_name'])->toBe('iPhone 16');
});

test('the device name falls back when the device reports nothing usable, and is trimmed to 80 characters', function () {
    $this->signIn->begin();
    parse_str((string) parse_url($this->browser->authUrls[0], PHP_URL_QUERY), $query);

    expect($query['device_name'])->toBe('WxNotify app');

    Native::fakeBridge()->respondTo('Device.GetInfo', ['info' => json_encode(['model' => str_repeat('x', 120)])]);
    $this->signIn->begin();
    parse_str((string) parse_url($this->browser->authUrls[1], PHP_URL_QUERY), $second);

    expect($second['device_name'])->toBe(str_repeat('x', 80));

    Native::fakeBridge()->respondTo('Device.GetInfo', ['info' => json_encode(['model' => "iPhone\x07 16"])]);
    $this->signIn->begin();
    parse_str((string) parse_url($this->browser->authUrls[2], PHP_URL_QUERY), $third);

    expect($third['device_name'])->not->toContain("\x07");
});

test('completing trades the code and verifier for a token and clears the pending sign-in', function () {
    $this->tokens->begin('the-verifier', 'the-state');
    Http::fake(['wx.test/api/v1/auth/token' => Http::response([
        'token' => '5|abc', 'expires_at' => '2027-01-01T00:00:00+00:00', 'user' => ['id' => 5, 'name' => 'Pat'],
    ])]);

    $user = $this->signIn->complete('the-code', 'the-state');

    expect($user)->toBe(['id' => 5, 'name' => 'Pat'])
        ->and($this->tokens->token())->toBe('5|abc')
        ->and($this->tokens->pending())->toBeNull()
        ->and((new LocalCache)->profile())->toBe(['id' => 5, 'name' => 'Pat']);

    Http::assertSent(fn (Request $r) => $r['code'] === 'the-code' && $r['code_verifier'] === 'the-verifier');
});

test('a state mismatch is refused without contacting the server, and the pending sign-in is spent', function () {
    $this->tokens->begin('the-verifier', 'the-state');
    Http::fake();

    expect(fn () => $this->signIn->complete('the-code', 'another-state'))->toThrow(InvalidSignIn::class);

    Http::assertNothingSent();
    expect($this->tokens->pending())->toBeNull()->and($this->tokens->token())->toBeNull();
});

test('a callback with no pending sign-in is refused', function () {
    Http::fake();

    expect(fn () => $this->signIn->complete('the-code', 'the-state'))->toThrow(InvalidSignIn::class);
    Http::assertNothingSent();
});

test('a rejected exchange stores no token', function () {
    $this->tokens->begin('the-verifier', 'the-state');
    Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 422)]);

    expect(fn () => $this->signIn->complete('the-code', 'the-state'))->toThrow(Rejected::class);
    expect($this->tokens->token())->toBeNull()->and($this->tokens->pending())->toBeNull();
});

test('a denied sign-in clears the pending state', function () {
    $this->tokens->begin('the-verifier', 'the-state');

    $this->signIn->denied();

    expect($this->tokens->pending())->toBeNull();
});
