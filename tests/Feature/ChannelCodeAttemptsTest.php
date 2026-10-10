<?php

use App\Actions\Channels\DispatchChannelConfirmationCode;
use App\Actions\Channels\VerifyChannelConfirmationCode;
use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->channel = NotificationChannel::factory()->unverified()->create(); // code 123456
    $this->verify = app(VerifyChannelConfirmationCode::class);
});

test('the right code verifies and clears the count', function () {
    expect(($this->verify)($this->channel->fresh(), '000000'))->toBeFalse()
        ->and(($this->verify)($this->channel->fresh(), '123456'))->toBeTrue()
        ->and($this->channel->fresh()->isVerified())->toBeTrue()
        ->and(Cache::has(VerifyChannelConfirmationCode::attemptsKey($this->channel)))->toBeFalse();
});

test('five wrong codes clear the stored code, so even the right one fails afterwards', function () {
    foreach (range(1, 5) as $_) {
        expect(($this->verify)($this->channel->fresh(), '000000'))->toBeFalse();
    }

    expect($this->channel->fresh()->confirmation_code)->toBeNull()
        ->and(($this->verify)($this->channel->fresh(), '123456'))->toBeFalse()
        ->and($this->channel->fresh()->isVerified())->toBeFalse();
});

test('four wrong codes leave the code usable', function () {
    foreach (range(1, 4) as $_) {
        ($this->verify)($this->channel->fresh(), '000000');
    }

    expect(($this->verify)($this->channel->fresh(), '123456'))->toBeTrue();
});

test('a stale copy that still holds the code cannot be guessed once the tries are spent', function () {
    $stale = $this->channel->fresh();
    $current = $this->channel->fresh();

    foreach (range(1, 5) as $_) {
        expect(($this->verify)($current->fresh(), '000000'))->toBeFalse();
    }

    expect($this->channel->fresh()->confirmation_code)->toBeNull()
        ->and($stale->confirmation_code)->toBe('123456')
        ->and(($this->verify)($stale, '123456'))->toBeFalse()
        ->and($this->channel->fresh()->isVerified())->toBeFalse();
});

test('the fifth try may still be the right one', function () {
    foreach (range(1, 4) as $_) {
        ($this->verify)($this->channel->fresh(), '000000');
    }

    expect(($this->verify)($this->channel->fresh(), '123456'))->toBeTrue();
});

test('sending a new code resets the count', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    foreach (range(1, 4) as $_) {
        ($this->verify)($this->channel->fresh(), '000000');
    }

    app(DispatchChannelConfirmationCode::class)($this->channel->fresh());
    $fresh = $this->channel->fresh();

    foreach (range(1, 4) as $_) {
        expect(($this->verify)($fresh->fresh(), '000000'))->toBeFalse();
    }

    expect(($this->verify)($fresh->fresh(), $fresh->confirmation_code))->toBeTrue();
});

test('the count is only reset once the new code has been saved', function () {
    $key = VerifyChannelConfirmationCode::attemptsKey($this->channel);
    Cache::put($key, 3, now()->addMinutes(30));
    NotificationChannel::saving(fn () => throw new RuntimeException('database down'));

    expect(fn () => app(DispatchChannelConfirmationCode::class)($this->channel->fresh()))->toThrow(RuntimeException::class)
        ->and(Cache::get($key))->toBe(3);
});
