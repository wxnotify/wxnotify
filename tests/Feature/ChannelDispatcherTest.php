<?php

use App\Enums\Tier;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\DeliveryResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

test('an apprise channel is delivered through apprise and a 200 is a success', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->create(['channel_type' => 'discord', 'apprise_url' => 'discord://1/abc/']);

    $result = app(ChannelDispatcher::class)->send($channel, 'body', 'title');

    expect($result)->toBeInstanceOf(DeliveryResult::class)
        ->and($result->success)->toBeTrue()->and($result->error)->toBeNull();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/notify/')
        && $request['urls'] === 'discord://1/abc/' && $request['body'] === 'body' && $request['title'] === 'title');
});

test('apprise reporting anything but 200 is a failure with the existing message', function () {
    Http::fake(['*/notify/*' => Http::response(status: 204)]);
    $channel = NotificationChannel::factory()->create(['channel_type' => 'discord']);

    $result = app(ChannelDispatcher::class)->send($channel, 'body');

    expect($result->success)->toBeFalse()->and($result->error)->toBe('Apprise reported non-delivery.');
});

test('a connection exception from apprise still propagates so queued jobs can retry', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('apprise is down')]);
    $channel = NotificationChannel::factory()->create(['channel_type' => 'discord']);

    expect(fn () => app(ChannelDispatcher::class)->send($channel, 'body'))->toThrow(ConnectionException::class);
});

test('an unknown channel type is a failed result, not an exception', function () {
    $channel = NotificationChannel::factory()->create(['channel_type' => 'carrier-pigeon']);

    $result = app(ChannelDispatcher::class)->send($channel, 'body');

    expect($result->success)->toBeFalse()->and($result->error)->toContain('carrier-pigeon');
});

test('the title is prefixed when the message prefix is enabled', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $settings = app(GeneralSettings::class);
    $settings->message_prefix_enabled = true;
    $settings->message_prefix = '[STAGING]';
    $settings->save();
    $channel = NotificationChannel::factory()->create(['channel_type' => 'discord', 'apprise_url' => 'discord://1/abc/']);

    app(ChannelDispatcher::class)->send($channel, 'body', 'title');

    Http::assertSent(fn ($request) => $request['title'] === '[STAGING] title');
});

test('a null title is left alone, not turned into a bare prefix', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $settings = app(GeneralSettings::class);
    $settings->message_prefix_enabled = true;
    $settings->message_prefix = '[STAGING]';
    $settings->save();
    $channel = NotificationChannel::factory()->create(['channel_type' => 'discord', 'apprise_url' => 'discord://1/abc/']);

    app(ChannelDispatcher::class)->send($channel, 'body');

    Http::assertSent(fn ($request) => ! array_key_exists('title', $request->data()));
});

test('config is stored encrypted and is not written to the activity log', function () {
    $channel = NotificationChannel::factory()->create([
        'channel_type' => 'discord', 'apprise_url' => null, 'config' => ['secret' => 'shh-super-secret'],
    ]);

    expect($channel->fresh()->config)->toBe(['secret' => 'shh-super-secret'])
        ->and(DB::table('notification_channels')->where('id', $channel->id)->value('config'))->not->toContain('shh-super-secret');

    $channel->update(['label' => 'renamed']);
    expect(json_encode(Activity::all()->toArray()))->not->toContain('shh-super-secret');
});

test('a downgraded subscriber\'s phone channel is refused at send time without calling Apprise', function () {
    chanEnablePhoneTypes();
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $channel = NotificationChannel::factory()->twilioSms()->for($user)->create();
    $rule = AlertRule::factory()->for($user)->create();
    $rule->notificationChannels()->attach($channel);

    $user->forceFill(['tier_override' => null])->save();

    foreach (['alert', 'test', 'confirmation'] as $type) {
        $result = app(ChannelDispatcher::class)->send($channel->fresh(), 'Tornado Warning', 'Alert', ['type' => $type, 'code' => '123456']);

        expect($result->success)->toBeFalse()
            ->and($result->error)->toBe('This channel needs the Plus plan or higher.');
    }

    Http::assertNothingSent();
    expect($channel->fresh())->not->toBeNull()
        ->and($rule->notificationChannels()->pluck('notification_channels.id')->all())->toBe([$channel->id]);
});

test('it works again after re-upgrade', function () {
    chanEnablePhoneTypes();
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $user = User::factory()->create(['tier_override' => null]);
    $channel = NotificationChannel::factory()->twilioSms()->for($user)->create();

    expect(app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert')->success)->toBeFalse();
    Http::assertNothingSent();

    $user->forceFill(['tier_override' => Tier::Pro])->save();

    expect(app(ChannelDispatcher::class)->send($channel->fresh(), 'Tornado Warning', 'Alert')->success)->toBeTrue();
    Http::assertSentCount(1);
});

test('an unverified phone channel gets its confirmation code but no alert or test', function () {
    chanEnablePhoneTypes();
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $channel = NotificationChannel::factory()->twilioSms()->for($user)->create(['verified_at' => null]);
    $dispatcher = app(ChannelDispatcher::class);

    foreach ([['type' => 'alert'], ['type' => 'test'], []] as $meta) {
        $result = $dispatcher->send($channel, 'Tornado Warning', 'Alert', $meta);

        expect($result->success)->toBeFalse()
            ->and($result->error)->toBe('This number has not been confirmed yet.');
    }

    Http::assertNothingSent();

    expect($dispatcher->send($channel, 'Your code is 654321', 'Confirm', ['type' => 'confirmation', 'code' => '654321'])->success)->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => str_contains($request['body'], 'WxNotify code: 654321.'));
});

test('a confirmation code still needs the plan', function () {
    chanEnablePhoneTypes();
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->twilioSms()->for(User::factory()->create(['tier_override' => null]))->create(['verified_at' => null]);

    $result = app(ChannelDispatcher::class)->send($channel, 'Your code is 654321', 'Confirm', ['type' => 'confirmation', 'code' => '654321']);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('This channel needs the Plus plan or higher.');
    Http::assertNothingSent();
});

test('non-phone channels are unaffected by the unverified rule', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->create([
        'channel_type' => 'discord', 'apprise_url' => 'discord://1/abc/', 'verified_at' => null,
    ]);

    $result = app(ChannelDispatcher::class)->send($channel, 'body', 'title', ['type' => 'alert']);

    expect($result->success)->toBeTrue();
    Http::assertSent(fn ($request) => $request['urls'] === 'discord://1/abc/' && $request['body'] === 'body');
});

test('an unconfirmed messaging channel gets its code but no alert or test', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $channel = NotificationChannel::factory()->googleChat()->for($user)->create(['verified_at' => null]);
    $dispatcher = app(ChannelDispatcher::class);

    foreach ([['type' => 'alert'], ['type' => 'test'], []] as $meta) {
        $result = $dispatcher->send($channel, 'Tornado Warning', 'Alert', $meta);

        expect($result->success)->toBeFalse()
            ->and($result->error)->toBe('This channel has not been confirmed yet.');
    }

    Http::assertNothingSent();

    $code = 'Your WxNotify confirmation code is: 654321';

    expect($dispatcher->send($channel, $code, 'Confirm', ['type' => 'confirmation', 'code' => '654321'])->success)->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request['body'] === $code && str_starts_with($request['urls'], 'gchat://'));
});

test('phone channels keep their unconfirmed message', function (string $state) {
    chanEnablePhoneTypes();
    Http::fake();
    $channel = NotificationChannel::factory()->{$state}()->for(User::factory()->create(['tier_override' => Tier::Plus]))->create(['verified_at' => null]);

    expect(app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert')->error)->toBe('This number has not been confirmed yet.');
    Http::assertNothingSent();
})->with(['twilioSms', 'bulkVsSms', 'httpSms']);

test('a downgraded subscriber\'s google chat channel is refused without calling Apprise', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $channel = NotificationChannel::factory()->googleChat()->for($user)->create();

    expect(app(ChannelDispatcher::class)->send($channel, 'Tornado Warning', 'Alert')->success)->toBeTrue();

    $user->forceFill(['tier_override' => null])->save();

    foreach (['alert', 'test', 'confirmation'] as $type) {
        expect(app(ChannelDispatcher::class)->send($channel->fresh(), 'Tornado Warning', 'Alert', ['type' => $type, 'code' => '123456'])->error)
            ->toBe(ChannelDispatcher::TIER_REFUSAL);
    }

    Http::assertSentCount(1);
    expect($channel->fresh())->not->toBeNull();
});
