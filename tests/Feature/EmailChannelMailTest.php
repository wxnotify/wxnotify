<?php

use App\Actions\Channels\DispatchChannelConfirmationCode;
use App\Actions\Channels\SendTestNotification;
use App\Mail\ChannelMessageMail;
use App\Models\NotificationChannel;
use App\Support\Delivery\ChannelDispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

test('the dispatcher sends an email channel through mail and never through apprise', function () {
    Mail::fake();
    Http::fake();
    $channel = NotificationChannel::factory()->email('subscriber@example.com')->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'alert body', 'Alert title');

    expect($result->success)->toBeTrue();
    Mail::assertSent(ChannelMessageMail::class, fn (ChannelMessageMail $mail) => $mail->hasTo('subscriber@example.com') && $mail->messageBody === 'alert body');
    Http::assertNothingSent();
});

test('a confirmation code for an email channel is mailed, not sent through apprise', function () {
    Mail::fake();
    Http::fake();
    $channel = NotificationChannel::factory()->email('subscriber@example.com')->unverified()->create();

    $sent = app(DispatchChannelConfirmationCode::class)($channel);

    $code = $channel->fresh()->confirmation_code;
    expect($sent)->toBeTrue()->and($code)->not->toBeNull();
    Mail::assertSent(ChannelMessageMail::class, fn (ChannelMessageMail $mail) => $mail->hasTo('subscriber@example.com') && str_contains($mail->messageBody, $code));
    Http::assertNothingSent();
});

test('send test for an email channel is mailed', function () {
    Mail::fake();
    Http::fake();
    $channel = NotificationChannel::factory()->email('subscriber@example.com')->create(['label' => 'My inbox']);

    expect(app(SendTestNotification::class)($channel))->toBeTrue();

    Mail::assertSent(ChannelMessageMail::class, fn (ChannelMessageMail $mail) => $mail->hasTo('subscriber@example.com') && str_contains($mail->messageBody, 'My inbox'));
    Http::assertNothingSent();
});

test('another channel type still goes through apprise and sends no mail', function () {
    Mail::fake();
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->create(['channel_type' => 'discord', 'apprise_url' => 'discord://1/abc/']);

    app(ChannelDispatcher::class)->send($channel, 'body', 'title');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/notify/'));
    Mail::assertNothingSent();
});
