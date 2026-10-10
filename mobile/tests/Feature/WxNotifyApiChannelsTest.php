<?php

use App\Exceptions\Api\ChannelLimitReached;
use App\Exceptions\Api\CodeNotSent;
use App\Exceptions\Api\Conflict;
use App\Exceptions\Api\InvalidCode;
use App\Exceptions\Api\RateLimited;
use App\Exceptions\Api\Retryable;
use App\Exceptions\Api\TestFailed;
use App\Exceptions\Api\ValidationFailed;
use App\Services\WxNotifyApi;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    (new TokenStore)->put('9|secret');
    $this->api = app(WxNotifyApi::class);
});

test('the channel types and the channels are unwrapped from their data key', function () {
    Http::fake([
        'wx.test/api/v1/channel-types' => Http::response(['data' => [['slug' => 'email']]]),
        'wx.test/api/v1/channels' => Http::response(['data' => [['id' => 4]]]),
    ]);

    expect($this->api->channelTypes())->toBe([['slug' => 'email']])
        ->and($this->api->channels())->toBe([['id' => 4]]);
});

test('each write goes to its endpoint with its body', function () {
    Http::fake([
        'wx.test/api/v1/channels' => Http::response(['data' => ['id' => 9]], 201),
        'wx.test/api/v1/channels/9/verify' => Http::response(['data' => ['id' => 9, 'verified' => true]]),
        'wx.test/api/v1/channels/9/resend' => Http::response(status: 204),
        'wx.test/api/v1/channels/9/test' => Http::response(status: 204),
        'wx.test/api/v1/channels/9' => fn (Request $request) => $request->method() === 'PUT'
            ? Http::response(['data' => ['id' => 9, 'label' => 'New']])
            : Http::response(status: 204),
    ]);

    expect($this->api->createChannel(['channel_type' => 'ntfy', 'topic' => 'wx']))->toBe(['id' => 9])
        ->and($this->api->verifyChannel(9, '123456'))->toBe(['id' => 9, 'verified' => true])
        ->and($this->api->renameChannel(9, 'New'))->toBe(['id' => 9, 'label' => 'New']);

    $this->api->resendChannelCode(9);
    $this->api->testChannel(9);
    $this->api->deleteChannel(9);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://wx.test/api/v1/channels' && $r->data() === ['channel_type' => 'ntfy', 'topic' => 'wx']);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/channels/9/verify') && $r->data() === ['code' => '123456']);
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->data() === ['label' => 'New']);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/channels/9'));
});

test('the server\'s refusals become typed exceptions', function (int $status, array $body, string $exception) {
    Http::fake(['wx.test/*' => Http::response($body, $status)]);

    expect(fn () => $this->api->createChannel(['channel_type' => 'ntfy']))->toThrow($exception);
})->with([
    'limit' => [422, ['error' => 'channel_limit', 'message' => 'Full.'], ChannelLimitReached::class],
    'wrong code' => [422, ['error' => 'invalid_code', 'message' => 'Nope.'], InvalidCode::class],
    'fields' => [422, ['message' => 'Invalid.', 'errors' => ['topic' => ['Bad topic.']]], ValidationFailed::class],
    'code not sent' => [502, ['error' => 'code_not_sent', 'message' => 'x'], CodeNotSent::class],
    'test failed' => [502, ['error' => 'test_failed', 'message' => 'x'], TestFailed::class],
    'in use' => [409, ['error' => 'channel_in_use', 'message' => 'x', 'alerts' => ['A']], Conflict::class],
    'a plain gateway error' => [502, ['message' => 'Bad gateway'], Retryable::class],
]);

test('a 429 is a RateLimited, which is still a Retryable', function () {
    Http::fake(['wx.test/*' => Http::response(['message' => 'Too Many Attempts.'], 429)]);

    expect(fn () => $this->api->createChannel(['channel_type' => 'ntfy']))->toThrow(RateLimited::class)
        ->and(fn () => $this->api->resendChannelCode(9))->toThrow(Retryable::class)
        ->and(new RateLimited)->toBeInstanceOf(Retryable::class);
});

// Http::fake() stubs stack and the first match wins, so one fake answers both calls by URL.
test('the exceptions carry the server\'s text and codes', function () {
    Http::fake([
        'wx.test/api/v1/channels/9/verify' => Http::response(['error' => 'invalid_code', 'message' => 'Nope.'], 422),
        'wx.test/api/v1/channels/9' => Http::response(['error' => 'channel_in_use', 'message' => 'In use.', 'alerts' => ['Storm']], 409),
    ]);

    try {
        $this->api->verifyChannel(9, '000000');
        $this->fail('verifyChannel should have thrown InvalidCode.');
    } catch (InvalidCode $e) {
        expect($e->getMessage())->toBe('Nope.');
    }

    try {
        $this->api->deleteChannel(9);
        $this->fail('deleteChannel should have thrown Conflict.');
    } catch (Conflict $e) {
        expect($e->reason)->toBe('channel_in_use')->and($e->getMessage())->toBe('In use.')->and($e->alerts)->toBe(['Storm']);
    }
});
