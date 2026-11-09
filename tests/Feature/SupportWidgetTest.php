<?php

use App\Enums\Tier;
use App\Models\User;
use STS\FilamentImpersonate\ImpersonateManager;

beforeEach(function () {
    config([
        'wxnotify.mode' => 'cloud',
        'services.chatwoot.base_url' => 'https://chat.example.test',
        'services.chatwoot.website_token' => 'site-token',
        'services.chatwoot.identity_secret' => 'secret',
    ]);
});

test('a signed-in subscriber gets the widget, identified by a signed hash and tier', function () {
    $subscriber = User::factory()->create(['name' => 'Pat Subscriber', 'email' => 'pat@example.test']);
    $subscriber->forceFill(['tier_override' => Tier::Plus])->save();

    $this->actingAs($subscriber)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('chatwootSDK', false)
        ->assertSee('https:\/\/chat.example.test', false)
        ->assertSee('site-token', false)
        ->assertSee(hash_hmac('sha256', (string) $subscriber->id, 'secret'), false)
        ->assertSee('pat@example.test', false)
        ->assertSee('setCustomAttributes', false)
        ->assertSee('"plus"', false);
});

test('a visitor who is not signed in gets no widget', function () {
    $this->get('/dashboard/login')->assertOk()->assertDontSee('chatwootSDK', false);
});

test('the widget is left out in self-host mode', function () {
    config(['wxnotify.mode' => 'selfhost']);

    $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->assertDontSee('chatwootSDK', false);
});

test('the widget is left out while staff impersonate a subscriber', function () {
    $staff = User::factory()->create(['is_staff' => true]);
    $subscriber = User::factory()->create();

    $this->actingAs($staff);
    app(ImpersonateManager::class)->enter($staff, $subscriber, 'web');

    $this->get('/dashboard')->assertOk()->assertDontSee('chatwootSDK', false);

    expect(auth()->id())->toBe($subscriber->id);
});

test('the widget is left out until the base URL and website token are both set', function (?string $baseUrl, ?string $token) {
    config(['services.chatwoot.base_url' => $baseUrl, 'services.chatwoot.website_token' => $token]);

    $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk()->assertDontSee('chatwootSDK', false);
})->with([
    'no base URL' => [null, 'site-token'],
    'no website token' => ['https://chat.example.test', null],
    'neither' => [null, null],
]);
