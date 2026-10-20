<?php

use App\Enums\Tier;
use App\Jobs\DeliverScheduledAlert;
use App\Mail\ChannelMessageMail;
use App\Mail\DailySummaryMail;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\Delivery\ChannelDispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

test('it delivers via apprise and writes a success DeliveryLog row', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $rule = AlertRule::factory()->dailySummary()->create();
    $channel = NotificationChannel::factory()->for($rule->user)->create(['apprise_url' => 'discord://token/id']);

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', 'Today: Sunny, high 88.'))
        ->handle(app(ChannelDispatcher::class));

    Http::assertSent(fn ($request) => $request['urls'] === 'discord://token/id' && $request['body'] === 'Today: Sunny, high 88.');

    $log = DeliveryLog::sole();
    expect($log->status)->toBe('success')
        ->and($log->error_detail)->toBeNull()
        ->and($log->alert_rule_id)->toBe($rule->id)
        ->and($log->notification_channel_id)->toBe($channel->id)
        ->and($log->channel_type)->toBe($channel->channel_type)
        // Not "Daily summary" (the ignored passed-in title) -- see the
        // "ignored default title" tests below for why displayName() wins.
        ->and($log->subject)->toBe($rule->displayName())
        ->and($log->ingested_product_id)->toBeNull();
});

test('every summary pushed to an iPhone counts as a new unread alert on top of the unread ones', function () {
    $credentials = fcmUseCredentials();
    fcmFakeGoogle();

    $rule = AlertRule::factory()->dailySummary()->create();
    $phone = NotificationChannel::factory()->for($rule->user)->mobilePush('ios-token-1', 'ios')->create();
    DeliveryLog::factory()->for($rule->user)->for(IngestedProduct::factory())->pushed()->create();

    (new DeliverScheduledAlert($rule->id, $phone->id, 'Daily summary', 'Today: Sunny.'))->handle(app(ChannelDispatcher::class));
    (new DeliverScheduledAlert($rule->id, $phone->id, 'Daily summary', 'Tomorrow: Rain.'))->handle(app(ChannelDispatcher::class));

    $badges = collect(Http::recorded())
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'messages:send'))
        ->map(fn (array $pair) => $pair[0]['message']['apns']['payload']['aps']['badge'])
        ->values()->all();

    expect($badges)->toBe([2, 3]);
    @unlink($credentials['path']);
});

test('a non-delivery from apprise writes a failure DeliveryLog row instead of throwing', function () {
    Http::fake(['*/notify/*' => Http::response(status: 204)]);

    $rule = AlertRule::factory()->dailySummary()->create();
    $channel = NotificationChannel::factory()->for($rule->user)->create();

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', 'body'))->handle(app(ChannelDispatcher::class));

    $log = DeliveryLog::sole();
    expect($log->status)->toBe('failure')
        ->and($log->error_detail)->not->toBeNull();
});

test('a deactivated rule is skipped and logged rather than delivered', function () {
    Http::fake();

    $rule = AlertRule::factory()->dailySummary()->create(['is_active' => false]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', 'body'))->handle(app(ChannelDispatcher::class));

    Http::assertNothingSent();
    $log = DeliveryLog::sole();
    expect($log->status)->toBe('skipped')
        ->and($log->subject)->toBe($rule->displayName())
        ->and($log->ingested_product_id)->toBeNull();
});

test('a missing rule sends nothing and logs a warning', function () {
    Http::fake();
    Log::spy();
    $channel = NotificationChannel::factory()->create();

    (new DeliverScheduledAlert(999999, $channel->id, 'title', 'body'))->handle(app(ChannelDispatcher::class));

    Http::assertNothingSent();
    expect(DeliveryLog::count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()
        ->withArgs(fn ($message, $context) => $context['rule_id'] === 999999 && $context['channel_id'] === $channel->id);
});

test('a missing channel sends nothing and logs a warning', function () {
    Http::fake();
    Log::spy();
    $rule = AlertRule::factory()->dailySummary()->create();

    (new DeliverScheduledAlert($rule->id, 999999, 'title', 'body'))->handle(app(ChannelDispatcher::class));

    Http::assertNothingSent();
    expect(DeliveryLog::count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()
        ->withArgs(fn ($message, $context) => $context['rule_id'] === $rule->id && $context['channel_id'] === 999999);
});

test('a custom subject template is used as the Apprise title instead of the passed-in title', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->dailySummary()->for($plusUser)->create(['custom_subject_template' => 'My custom title']);
    $channel = NotificationChannel::factory()->for($rule->user)->create();

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Ignored default title', 'Today: Sunny, high 88.'))
        ->handle(app(ChannelDispatcher::class));

    Http::assertSent(fn ($request) => $request['title'] === 'My custom title');
});

test('message_body_style Brief truncates the passed-in body to 280 characters', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->dailySummary()->for($plusUser)->create(['message_body_style' => 'brief']);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $longBody = str_repeat('Sunny with a high of 88 degrees. ', 30);

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', $longBody))
        ->handle(app(ChannelDispatcher::class));

    Http::assertSent(fn ($request) => strlen($request['body']) <= 280);
});

test('message_body_style Brief does not truncate an email alert either', function () {
    Mail::fake();
    Http::fake();
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->dailySummary()->for($plusUser)->create(['message_body_style' => 'brief']);
    $channel = NotificationChannel::factory()->for($rule->user)->email('subscriber@example.com')->create();
    $longBody = str_repeat('Sunny with a high of 88 degrees. ', 30);

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', $longBody))
        ->handle(app(ChannelDispatcher::class));

    Mail::assertSent(ChannelMessageMail::class, fn (ChannelMessageMail $mail) => $mail->messageBody === $longBody);
    Http::assertNothingSent();
});

test('message_body_style Full (or null) preserves the passed-in body unchanged', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->dailySummary()->for($plusUser)->create(['message_body_style' => null]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', 'Today: Sunny, high 88.'))
        ->handle(app(ChannelDispatcher::class));

    Http::assertSent(fn ($request) => $request['body'] === 'Today: Sunny, high 88.');
});

test('a Free-tier owner\'s custom subject and Brief style are ignored at delivery', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $freeUser = User::factory()->create(['tier_override' => Tier::Free]);
    $rule = AlertRule::factory()->dailySummary()->for($freeUser)->create([
        'custom_subject_template' => 'My custom title',
        'message_body_style' => 'brief',
    ]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $longBody = str_repeat('Sunny with a high of 88 degrees. ', 30);

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Ignored default title', $longBody))
        ->handle(app(ChannelDispatcher::class));

    Http::assertSent(fn ($request) => $request['title'] === $rule->displayName() && $request['body'] === $longBody);
});

test('the default title (no custom template) still reaches Apprise as displayName()', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->dailySummary()->for($plusUser)->create(['custom_subject_template' => null]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Ignored default title', 'Today: Sunny, high 88.'))
        ->handle(app(ChannelDispatcher::class));

    Http::assertSent(fn ($request) => $request['title'] === $rule->displayName());
});

function dsReport(): array
{
    return presenterReport(['radar_site' => 'KFTG']);
}

test('a Full summary with a report sends the rich mail with the radar image to an email channel', function () {
    Mail::fake();
    Http::fake(['radar.weather.gov/*' => Http::response("GIF89a\x01\x00\x01\x00\x00\x00\x00;", 200, ['Content-Type' => 'image/gif'])]);
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->dailySummary()->for($plusUser)->create(['message_body_style' => 'full']);
    $channel = NotificationChannel::factory()->for($plusUser)->email('subscriber@example.com')->create();

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', 'Today: Sunny, high 79.', dsReport()))
        ->handle(app(ChannelDispatcher::class));

    Mail::assertSent(DailySummaryMail::class, fn (DailySummaryMail $mail) => $mail->hasTo('subscriber@example.com')
        && $mail->briefText === 'Today: Sunny, high 79.'
        && $mail->radar !== null
        && $mail->radar['mime'] === 'image/gif');
    Mail::assertNotSent(ChannelMessageMail::class);
    expect(DeliveryLog::sole()->status)->toBe('success');
});

test('the rich mail is still sent, without radar, when the radar fetch fails', function () {
    Mail::fake();
    Http::fake(['radar.weather.gov/*' => Http::response('', 500)]);
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->dailySummary()->for($plusUser)->create(['message_body_style' => 'full']);
    $channel = NotificationChannel::factory()->for($plusUser)->email('subscriber@example.com')->create();

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', 'Brief.', dsReport()))
        ->handle(app(ChannelDispatcher::class));

    Mail::assertSent(DailySummaryMail::class, fn (DailySummaryMail $mail) => $mail->radar === null);
});

test('a report with no radar site never requests radar', function () {
    Mail::fake();
    Http::fake();
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->dailySummary()->for($plusUser)->create(['message_body_style' => 'full']);
    $channel = NotificationChannel::factory()->for($plusUser)->email('subscriber@example.com')->create();

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', 'Brief.', presenterReport(['radar_site' => null])))
        ->handle(app(ChannelDispatcher::class));

    Mail::assertSent(DailySummaryMail::class);
    Http::assertNothingSent();
});

test('a Full summary report sent to a non-rich channel delivers the Brief text and fetches no radar', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->dailySummary()->for($plusUser)->create(['message_body_style' => 'full']);
    $channel = NotificationChannel::factory()->for($plusUser)->create(['apprise_url' => 'discord://token/id']);

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', 'Today: Sunny, high 79.', dsReport()))
        ->handle(app(ChannelDispatcher::class));

    Http::assertSent(fn ($request) => $request['body'] === 'Today: Sunny, high 79.');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'radar.weather.gov'));
    expect(DeliveryLog::sole()->status)->toBe('success');
});

test('with no report an email channel gets the plain channel message as before', function () {
    Mail::fake();
    Http::fake();
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->dailySummary()->for($plusUser)->create(['message_body_style' => 'full']);
    $channel = NotificationChannel::factory()->for($plusUser)->email('subscriber@example.com')->create();

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', 'Brief.'))
        ->handle(app(ChannelDispatcher::class));

    Mail::assertSent(ChannelMessageMail::class);
    Mail::assertNotSent(DailySummaryMail::class);
});

test('a job queued before the report property existed unserializes without it and still delivers', function () {
    Mail::fake();
    Http::fake();
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->dailySummary()->for($plusUser)->create(['message_body_style' => 'full']);
    $channel = NotificationChannel::factory()->for($plusUser)->email('subscriber@example.com')->create();

    // Reproduces a job serialized by the pre-deploy code: native unserialize()
    // bypasses the constructor, so the new typed readonly `?array $report` is
    // left genuinely uninitialized (not null).
    $reflection = new ReflectionClass(DeliverScheduledAlert::class);
    $job = $reflection->newInstanceWithoutConstructor();
    foreach (['ruleId' => $rule->id, 'channelId' => $channel->id, 'title' => 'Daily summary', 'body' => 'Brief.'] as $property => $value) {
        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($job, $value);
    }

    $job->handle(app(ChannelDispatcher::class));

    Mail::assertSent(ChannelMessageMail::class);
    expect(DeliveryLog::sole()->status)->toBe('success');
});
