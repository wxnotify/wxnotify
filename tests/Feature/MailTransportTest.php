<?php

use App\Mail\ChannelMessageMail;
use App\Models\NotificationChannel;
use App\Settings\IntegrationSettings;
use App\Support\Delivery\Transports\MailTransport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

test('it sends one branded message to the channel address and reports delivered', function () {
    Mail::fake();
    $channel = NotificationChannel::factory()->email('subscriber@example.com')->create();

    $result = app(MailTransport::class)->send($channel, "body\ntext", 'Test title');

    expect($result->success)->toBeTrue()->and($result->error)->toBeNull();
    Mail::assertSentCount(1);
    Mail::assertSent(ChannelMessageMail::class, fn (ChannelMessageMail $mail) => $mail->hasTo('subscriber@example.com')
        && $mail->messageTitle === 'Test title'
        && $mail->messageBody === "body\ntext");
});

test('a url in the meta is passed through to the mailable as its view link', function () {
    Mail::fake();
    $channel = NotificationChannel::factory()->email('subscriber@example.com')->create();

    app(MailTransport::class)->send($channel, 'body', 'title', ['url' => 'https://wxnotify.test/products/kbou-afd-271728']);

    Mail::assertSent(ChannelMessageMail::class, fn (ChannelMessageMail $mail) => $mail->viewUrl === 'https://wxnotify.test/products/kbou-afd-271728');
});

test('no url in the meta means no link on the mailable', function () {
    Mail::fake();
    $channel = NotificationChannel::factory()->email('subscriber@example.com')->create();

    app(MailTransport::class)->send($channel, 'body', 'title');

    Mail::assertSent(ChannelMessageMail::class, fn (ChannelMessageMail $mail) => $mail->viewUrl === null);
});

test('a channel without an address fails with a fixed message and sends nothing', function () {
    Mail::fake();
    $channel = NotificationChannel::factory()->email()->create(['config' => []]);

    $result = app(MailTransport::class)->send($channel, 'body', 'title');

    expect($result->success)->toBeFalse()->and($result->error)->toBe('This email channel is missing its address.');
    Mail::assertNothingSent();
});

test('a mailer exception becomes a fixed failure with no exception text or address in it', function () {
    $channel = NotificationChannel::factory()->email('secret-recipient@example.com')->create();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp.internal.example: 535 auth failed for secret-recipient@example.com'));

    $result = app(MailTransport::class)->send($channel, 'body', 'title');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('The mail server could not send the message.')
        ->and($result->error)->not->toContain('smtp.internal')
        ->and($result->error)->not->toContain('secret-recipient');
});

test('with the email relay turned off nothing is sent and the failure is fixed', function () {
    Mail::fake();
    $settings = app(IntegrationSettings::class);
    $settings->email_relay_enabled = false;
    $settings->save();
    $channel = NotificationChannel::factory()->email('subscriber@example.com')->create();

    $result = app(MailTransport::class)->send($channel, 'body', 'title');

    expect($result->success)->toBeFalse()->and($result->error)->toBe('Email delivery is turned off on this server.');
    Mail::assertNothingSent();
});

test('with the email relay on the message is sent', function () {
    Mail::fake();
    $channel = NotificationChannel::factory()->email('subscriber@example.com')->create();

    expect(app(MailTransport::class)->send($channel, 'body', 'title')->success)->toBeTrue();
    Mail::assertSentCount(1);
});

test('an unreadable settings store does not block sending', function () {
    Mail::fake();
    app()->bind(IntegrationSettings::class, fn () => throw new RuntimeException('settings store down'));
    $channel = NotificationChannel::factory()->email('subscriber@example.com')->create();

    expect(app(MailTransport::class)->send($channel, 'body', 'title')->success)->toBeTrue();
    Mail::assertSentCount(1);
});

test('a send failure logs only the channel id and exception class', function () {
    Log::spy();
    $channel = NotificationChannel::factory()->email('secret-recipient@example.com')->create();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp.internal.example: auth failed for secret-recipient@example.com'));

    app(MailTransport::class)->send($channel, 'body', 'title');

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($channel) {
        return $message === 'Email channel send failed'
            && $context === ['notification_channel_id' => $channel->id, 'exception' => RuntimeException::class]
            && ! str_contains(json_encode($context), 'secret-recipient')
            && ! str_contains(json_encode($context), 'smtp.internal');
    })->once();
});
