<?php

use App\Actions\Channels\DispatchChannelConfirmationCode;
use App\Actions\Channels\VerifyChannelConfirmationCode;
use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->channel = NotificationChannel::factory()->unverified()->create(); // code 123456
    $this->verify = app(VerifyChannelConfirmationCode::class);
});

test('the right code verifies and clears the count', function () {
    expect(($this->verify)($this->channel->fresh(), '000000'))->toBeFalse()
        ->and(($this->verify)($this->channel->fresh(), '123456'))->toBeTrue()
        ->and($this->channel->fresh()->isVerified())->toBeTrue();
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
