<?php

use App\Mail\ChannelMessageMail;
use App\Models\NotificationChannel;
use App\Support\Delivery\Transports\MailTransport;
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
