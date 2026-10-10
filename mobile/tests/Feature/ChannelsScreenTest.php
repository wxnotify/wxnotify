<?php

use App\NativeComponents\ChannelsScreen;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function listedChannel(array $overrides = []): array
{
    return [
        'id' => 1, 'label' => 'Team Discord', 'channel_type' => 'discord', 'type_label' => 'Discord', 'destination' => '••••1234',
        'destination_label' => 'Discord webhook', 'verified' => true, 'managed_by_app' => false, 'created_at' => '2026-10-01T12:00:00+00:00',
        ...$overrides,
    ];
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('the list shows each channel with its type, masked destination and whether it still needs verifying', function () {
    Http::fake(['wx.test/api/v1/channels' => Http::response(['data' => [
        listedChannel(),
        listedChannel(['id' => 2, 'label' => 'Work email', 'channel_type' => 'email', 'type_label' => 'Email', 'destination' => 'pat@example.com', 'verified' => false]),
        listedChannel(['id' => 3, 'label' => 'Pixel 9', 'channel_type' => 'mobile_push', 'type_label' => 'Mobile Push', 'destination' => null, 'managed_by_app' => true]),
    ]])]);

    Native::test(ChannelsScreen::class)
        ->assertNavTitle('Channels')
        ->assertHasTab('Alerts')
        ->assertTabActive('Settings')
        ->assertSee('Team Discord')
        ->assertSee('Discord · ••••1234')
        ->assertSee('Email · pat@example.com · Not verified')
        ->assertSee('Mobile Push · Managed in Account')
        ->assertElement('button', fn (array $b) => $b['props']['label'] === 'Add channel');
});

test('tapping a channel opens it, Add channel opens the new-channel screen, and a phone\'s own channel does not open', function () {
    Http::fake(['wx.test/api/v1/channels' => Http::response(['data' => [listedChannel(), listedChannel(['id' => 3, 'label' => 'Pixel 9', 'managed_by_app' => true])]])]);

    Native::test(ChannelsScreen::class)
        ->tap('channel-1')
        ->assertNavigatedTo('/channels/1');

    Native::test(ChannelsScreen::class)->tap('add-channel')->assertNavigatedTo('/channels/new');

    Native::test(ChannelsScreen::class)->assertMissingElement('pressable', fn (array $p) => ($p['ref'] ?? null) === 'channel-3');
});

test('with no channels it says so', function () {
    Http::fake(['wx.test/api/v1/channels' => Http::response(['data' => []])]);

    Native::test(ChannelsScreen::class)->assertSee('No channels yet');
});

test('offline it shows the last list with a note, and with nothing remembered it does not claim there are none', function () {
    (new LocalCache)->rememberChannels([listedChannel()]);
    $this->network->connected = false;

    Native::test(ChannelsScreen::class)
        ->assertSee('You\'re offline. Showing your channels as last loaded.')
        ->assertSee('Team Discord');

    (new LocalCache)->wipe();

    Native::test(ChannelsScreen::class)->assertSee('You\'re offline.')->assertDontSee('No channels yet');
});

test('a failing server shows a message with Try again', function () {
    Http::fake(['wx.test/api/v1/channels' => Http::response(['message' => 'boom'], 500)]);

    Native::test(ChannelsScreen::class)->assertSee('WxNotify could not load your channels. Try again in a moment.')->assertSee('Try again');
});

test('the list is remembered when it loads', function () {
    Http::fake(['wx.test/api/v1/channels' => Http::response(['data' => [listedChannel()]])]);

    Native::test(ChannelsScreen::class);

    expect((new LocalCache)->channels())->toHaveCount(1);
});

test('a signed-out visitor is sent to Sign in, and a rejected token signs out', function () {
    (new TokenStore)->forget();
    Native::test(ChannelsScreen::class)->assertReplacedWith('/sign-in');

    (new TokenStore)->put('9|secret');
    Http::fake(['wx.test/api/v1/channels' => Http::response([], 401)]);
    Native::test(ChannelsScreen::class)->assertReplacedWith('/sign-in');
});
