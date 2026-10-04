<?php

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Pat Smith', 'timezone' => 'America/Chicago']);
});

test('an app token reads the subscriber summary', function () {
    $token = $this->user->createToken('iPhone', ['app'], now()->addDays(90))->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/me')
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $this->user->id,
            'name' => 'Pat Smith',
            'email' => $this->user->email,
            'tier' => ['value' => 'free', 'label' => 'Free'],
            'unit_system' => 'imperial',
            'timezone' => 'America/Chicago',
        ]]);
});

test('a request without a token is refused with JSON', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

test('a token without the app ability is refused', function () {
    $token = $this->user->createToken('My script', ['api'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();
});

test('an expired token is refused', function () {
    $token = $this->user->createToken('iPhone', ['app'], now()->subMinute())->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
});

test('a dashboard session does not authenticate the API', function () {
    $this->actingAs($this->user)->getJson('/api/v1/me')->assertUnauthorized();
});

test('signing out revokes only the calling token', function () {
    $calling = $this->user->createToken('This phone', ['app']);
    $this->user->createToken('Other phone', ['app']);

    $this->withToken($calling->plainTextToken)->deleteJson('/api/v1/auth/token')->assertNoContent();

    expect($this->user->tokens()->pluck('name')->all())->toBe(['Other phone']);
});

test('a token last extended over a day ago gets a fresh 90 days', function () {
    $token = $this->user->createToken('iPhone', ['app'], now()->addDays(80));

    $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertOk();

    expect($token->accessToken->fresh()->expires_at->toDateString())->toBe(now()->addDays(90)->toDateString());
});

test('a token extended today is not rewritten', function () {
    $expires = now()->addDays(90)->subHours(2)->startOfSecond();
    $token = $this->user->createToken('iPhone', ['app'], $expires);

    $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertOk();

    expect($token->accessToken->fresh()->expires_at->toDateTimeString())->toBe($expires->toDateTimeString());
});

test('the API is limited to 120 requests a minute per token', function () {
    $token = $this->user->createToken('iPhone', ['app'])->plainTextToken;

    foreach (range(1, 120) as $_) {
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    }

    $this->withToken($token)->getJson('/api/v1/me')->assertStatus(429);
});
