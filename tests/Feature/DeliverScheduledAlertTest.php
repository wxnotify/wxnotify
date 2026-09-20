<?php

use App\Enums\Tier;
use App\Jobs\DeliverScheduledAlert;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\Delivery\ChannelDispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        ->and($log->channel_type)->toBe($channel->channel_type);
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
    expect($log->status)->toBe('skipped');
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
