<?php

use App\Actions\AppAuth\IssueAppAuthorizationCode;
use App\Models\User;
use App\Support\ClientIp;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Models\Activity;

afterEach(function () {
    Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    TrustProxies::flushState();
});

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->verifier = str_repeat('v', 43);
    $this->challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
    $this->code = (new IssueAppAuthorizationCode)($this->user, $this->challenge, 'iPhone 16');
});

test('the code and verifier are exchanged once for an app token', function () {
    $this->freezeTime();

    $response = $this->postJson('/api/v1/auth/token', ['code' => $this->code, 'code_verifier' => $this->verifier])
        ->assertOk()
        ->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'name', 'email', 'tier', 'unit_system', 'timezone']]);

    $token = $this->user->tokens()->sole();

    expect($token->name)->toBe('iPhone 16')
        ->and($token->abilities)->toBe(['app'])
        ->and($token->expires_at->toDateString())->toBe(now()->addDays(90)->toDateString())
        ->and($response->json('user.id'))->toBe($this->user->id);

    $this->withToken($response->json('token'))->getJson('/api/v1/me')->assertOk();
});

test('a replayed code fails', function () {
    $this->postJson('/api/v1/auth/token', ['code' => $this->code, 'code_verifier' => $this->verifier])->assertOk();

    $this->postJson('/api/v1/auth/token', ['code' => $this->code, 'code_verifier' => $this->verifier])
        ->assertStatus(422)->assertJson(['error' => 'invalid_grant']);
});

test('a wrong verifier fails and also burns the code', function () {
    $this->postJson('/api/v1/auth/token', ['code' => $this->code, 'code_verifier' => str_repeat('x', 43)])
        ->assertStatus(422)->assertJson(['error' => 'invalid_grant']);

    $this->postJson('/api/v1/auth/token', ['code' => $this->code, 'code_verifier' => $this->verifier])
        ->assertStatus(422)->assertJson(['error' => 'invalid_grant']);

    expect($this->user->tokens()->count())->toBe(0);
});

test('an expired code fails', function () {
    $this->travel(121)->seconds();

    $this->postJson('/api/v1/auth/token', ['code' => $this->code, 'code_verifier' => $this->verifier])
        ->assertStatus(422)->assertJson(['error' => 'invalid_grant']);
});

test('an unknown or malformed code fails with the same generic error', function (array $body) {
    $this->postJson('/api/v1/auth/token', $body)
        ->assertStatus(422)->assertExactJson(['error' => 'invalid_grant', 'message' => 'The sign-in could not be completed. Start again from the app.']);
})->with([
    'unknown code' => [['code' => str_repeat('a', 64), 'code_verifier' => str_repeat('v', 43)]],
    'short code' => [['code' => 'abc', 'code_verifier' => str_repeat('v', 43)]],
    'no verifier' => [['code' => str_repeat('a', 64)]],
    'nothing' => [[]],
]);

test('a subscriber whose email is no longer verified cannot exchange', function () {
    $this->user->forceFill(['email_verified_at' => null])->save();

    $this->postJson('/api/v1/auth/token', ['code' => $this->code, 'code_verifier' => $this->verifier])->assertStatus(422);
    expect($this->user->tokens()->count())->toBe(0);
});

test('the token is named from the stored device name, not the request', function () {
    $this->postJson('/api/v1/auth/token', ['code' => $this->code, 'code_verifier' => $this->verifier, 'device_name' => 'Spoofed'])->assertOk();

    expect($this->user->tokens()->sole()->name)->toBe('iPhone 16');
});

test('a successful exchange is recorded without the token', function () {
    $response = $this->postJson('/api/v1/auth/token', ['code' => $this->code, 'code_verifier' => $this->verifier])->assertOk();

    $entry = Activity::where('event', 'app-signed-in')->sole();

    expect($entry->causer_id)->toBe($this->user->id)
        ->and($entry->properties->get('device'))->toBe('iPhone 16')
        ->and(json_encode($entry->toArray()))->not->toContain(explode('|', $response->json('token'))[1]);
});

test('the exchange is limited to 10 requests a minute per IP', function () {
    foreach (range(1, 10) as $_) {
        $this->postJson('/api/v1/auth/token', ['code' => str_repeat('a', 64), 'code_verifier' => str_repeat('v', 43)])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/token', ['code' => str_repeat('a', 64), 'code_verifier' => str_repeat('v', 43)])->assertStatus(429);
});

test('the code is stored only as a hash', function () {
    expect(Cache::get('app-auth-code:'.$this->code))->toBeNull()
        ->and(Cache::get(IssueAppAuthorizationCode::key($this->code)))->toBeArray();
});

test('the exchange limit is per client address as the project resolves it, not per proxy or spoofable header', function () {
    config(['wxnotify.client_ip.trusted_proxies' => '*', 'wxnotify.client_ip.header' => 'CF-Connecting-IP']);
    ClientIp::configureProxies();

    $exchange = fn (string $ip, string $forwardedFor = '198.51.100.9') => $this
        ->withHeaders(['CF-Connecting-IP' => $ip, 'X-Forwarded-For' => $forwardedFor])
        ->postJson('/api/v1/auth/token', ['code' => str_repeat('a', 64), 'code_verifier' => str_repeat('v', 43)]);

    foreach (range(1, 10) as $_) {
        $exchange('203.0.113.1', fake()->ipv4())->assertStatus(422);
    }

    $exchange('203.0.113.2')->assertStatus(422);
    $exchange('203.0.113.1')->assertStatus(429);
});
