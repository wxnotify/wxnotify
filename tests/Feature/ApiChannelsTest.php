<?php

use App\Enums\Tier;
use App\Models\NotificationChannel;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'pat@example.com', 'tier_override' => Tier::Free]);
    $this->phone = $this->user->createToken('Pixel 9', ['app']);
});

function apiChannelCall($test, string $method, string $uri, array $body = [], ?string $token = null)
{
    app('auth')->forgetGuards();

    return $test->withToken($token ?? $test->phone->plainTextToken)->json($method, $uri, $body);
}

test('the channel endpoints need an app token', function () {
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/channels')->assertUnauthorized();

    $beacon = $this->user->createToken('beacon', ['location'])->plainTextToken;
    apiChannelCall($this, 'GET', '/api/v1/channel-types', [], $beacon)->assertForbidden();
});

test('the types are the six the app can create, with their fields and the email defaulted to the subscriber\'s own', function () {
    $response = apiChannelCall($this, 'GET', '/api/v1/channel-types')->assertOk();

    $types = collect($response->json('data'))->keyBy('slug');

    expect($types->keys()->all())->toEqualCanonicalizing(['email', 'ntfy', 'discord', 'slack', 'telegram', 'msteams'])
        ->and($types['email']['fields'][0])->toBe([
            'key' => 'to', 'label' => 'Email address', 'input' => 'email', 'required' => true,
            'help' => null, 'placeholder' => null, 'default' => 'pat@example.com',
        ])
        ->and($types['discord']['fields'][0]['input'])->toBe('secret')
        ->and($types['ntfy']['fields'][1]['required'])->toBeFalse()
        ->and($types['discord'])->toHaveKeys(['label', 'can_add', 'unavailable_reason'])
        ->and($response->getContent())->not->toContain('regex');
});

test('a Free subscriber who used the no-cost slot cannot add another type but can still add email', function () {
    NotificationChannel::factory()->for($this->user)->create(['channel_type' => 'discord']);

    $types = collect(apiChannelCall($this, 'GET', '/api/v1/channel-types')->json('data'))->keyBy('slug');

    expect($types['slack']['can_add'])->toBeFalse()
        ->and($types['slack']['unavailable_reason'])->toBe('channel_limit')
        ->and($types['email']['can_add'])->toBeTrue()
        ->and($types['email']['unavailable_reason'])->toBeNull();
});

test('the list is the subscriber\'s own channels, oldest first, of every type, and never carries a destination url, config or token', function () {
    $discord = NotificationChannel::factory()->for($this->user)->create(['label' => 'Team Discord', 'apprise_url' => 'discord://123456789/SECRETTOKEN1234/']);
    $email = NotificationChannel::factory()->for($this->user)->email('pat@example.com')->unverified()->create(['label' => 'My email']);
    $phone = NotificationChannel::factory()->for($this->user)->mobilePush('fcm-secret-token')->create();
    NotificationChannel::factory()->create(['label' => 'Theirs']);

    $response = apiChannelCall($this, 'GET', '/api/v1/channels')->assertOk();
    $rows = collect($response->json('data'))->keyBy('id');

    expect($rows->keys()->all())->toBe([$discord->id, $email->id, $phone->id])
        ->and($rows[$discord->id])->toMatchArray([
            'label' => 'Team Discord', 'channel_type' => 'discord', 'type_label' => 'Discord',
            'destination' => '••••1234', 'destination_label' => 'Discord webhook', 'verified' => true, 'managed_by_app' => false,
        ])
        ->and($rows[$email->id]['verified'])->toBeFalse()
        ->and($rows[$phone->id]['managed_by_app'])->toBeTrue()
        ->and($response->getContent())->not->toContain('SECRETTOKEN')
        ->not->toContain('fcm-secret-token')
        ->not->toContain('apprise_url')
        ->not->toContain('config');
});
