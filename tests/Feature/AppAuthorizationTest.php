<?php

use App\Models\User;
use STS\FilamentImpersonate\ImpersonateManager;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->verifier = str_repeat('v', 43);
    $this->challenge = rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '=');
    $this->query = [
        'challenge' => $this->challenge,
        'state' => str_repeat('s', 32),
        'device_name' => 'iPhone 16',
        'redirect' => 'wxnotify://auth/callback',
    ];
});

test('a guest is sent to the dashboard login and returned to the authorize page afterwards', function () {
    $url = '/app-auth/authorize?'.http_build_query($this->query);

    $this->get($url)->assertRedirect(route('filament.dashboard.auth.login'));

    $intended = parse_url(session('url.intended'));
    parse_str($intended['query'], $intendedQuery);

    expect(url($intended['path']))->toBe(url('/app-auth/authorize'))
        ->and($intendedQuery)->toBe(collect($this->query)->sortKeys()->all());
});

test('the consent page names the device and the account, escaped', function () {
    $this->actingAs($this->user)
        ->get('/app-auth/authorize?'.http_build_query([...$this->query, 'device_name' => '<script>alert(1)</script>']))
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', escape: false)
        ->assertDontSee('<script>alert(1)</script>', escape: false)
        ->assertSee($this->user->email);
});

test('malformed parameters are rejected', function (string $key, string $value) {
    $this->actingAs($this->user)
        ->get('/app-auth/authorize?'.http_build_query([...$this->query, $key => $value]))
        ->assertStatus(422);
})->with([
    'short challenge' => ['challenge', 'abc'],
    'bad state characters' => ['state', str_repeat('!', 20)],
    'short state' => ['state', 'short'],
    'empty device' => ['device_name', ''],
    'long device' => ['device_name', str_repeat('d', 81)],
    'control characters in device' => ['device_name', "bad\x07name"],
    'wrong redirect' => ['redirect', 'https://evil.example/callback'],
]);

test('an unverified subscriber is sent to email verification', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get('/app-auth/authorize?'.http_build_query($this->query))
        ->assertRedirect(route('filament.dashboard.auth.email-verification.prompt'));
});

test('staff impersonating a subscriber are refused and no code is issued', function () {
    $this->mock(ImpersonateManager::class, fn ($mock) => $mock->shouldReceive('isImpersonating')->andReturn(true));

    $this->actingAs($this->user)->get('/app-auth/authorize?'.http_build_query($this->query))->assertForbidden();
    $this->actingAs($this->user)->post('/app-auth/authorize', [...$this->query, 'decision' => 'allow'])->assertForbidden();
});

test('Allow redirects to the app with a code and the state in the path', function () {
    $response = $this->actingAs($this->user)
        ->post('/app-auth/authorize', [...$this->query, 'decision' => 'allow'])
        ->assertRedirect();

    expect($response->headers->get('Location'))->toMatch('#^wxnotify://auth/callback/[A-Za-z0-9]{64}/'.preg_quote($this->query['state'], '#').'$#');
});

test('Cancel redirects to the app as denied, with the state, and issues no code', function () {
    $this->actingAs($this->user)
        ->post('/app-auth/authorize', [...$this->query, 'decision' => 'deny'])
        ->assertRedirect('wxnotify://auth/denied/'.$this->query['state']);
});

test('the decision must be allow or deny', function () {
    $this->actingAs($this->user)->post('/app-auth/authorize', [...$this->query, 'decision' => 'maybe'])->assertStatus(422);
});
