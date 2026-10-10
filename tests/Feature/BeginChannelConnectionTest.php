<?php

use App\Actions\Channels\BeginChannelConnection;
use App\Exceptions\ChannelCodeNotSent;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->type = app(ChannelTypeRegistry::class)->find('ntfy');
});

test('it stores an unverified channel with the type\'s label and sends the code', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $channel = app(BeginChannelConnection::class)($this->user, $this->type, ['topic' => 'wx-topic']);

    expect($channel->user_id)->toBe($this->user->id)
        ->and($channel->channel_type)->toBe('ntfy')
        ->and($channel->label)->toBe($this->type->label())
        ->and($channel->apprise_url)->toBe('ntfy://wx-topic')
        ->and($channel->isVerified())->toBeFalse()
        ->and($channel->confirmation_code)->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/notify/'));
});

test('a label given by the subscriber wins', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $channel = app(BeginChannelConnection::class)($this->user, $this->type, ['topic' => 'wx-topic'], 'Phone alerts');

    expect($channel->label)->toBe('Phone alerts');
});

test('when the code cannot be sent the new channel is removed again', function () {
    Http::fake(['*/notify/*' => Http::failedConnection()]);

    expect(fn () => app(BeginChannelConnection::class)($this->user, $this->type, ['topic' => 'wx-topic']))
        ->toThrow(ChannelCodeNotSent::class);

    expect(NotificationChannel::where('user_id', $this->user->id)->count())->toBe(0);
});
