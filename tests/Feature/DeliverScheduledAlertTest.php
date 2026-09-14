<?php

use App\Jobs\DeliverScheduledAlert;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\NotificationChannel;
use App\Support\Apprise\AppriseApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

test('it delivers via apprise and writes a success DeliveryLog row', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $rule = AlertRule::factory()->dailySummary()->create();
    $channel = NotificationChannel::factory()->for($rule->user)->create(['apprise_url' => 'discord://token/id']);

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', 'Today: Sunny, high 88.'))
        ->handle(app(AppriseApiClient::class));

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

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', 'body'))->handle(app(AppriseApiClient::class));

    $log = DeliveryLog::sole();
    expect($log->status)->toBe('failure')
        ->and($log->error_detail)->not->toBeNull();
});

test('a deactivated rule is skipped and logged rather than delivered', function () {
    Http::fake();

    $rule = AlertRule::factory()->dailySummary()->create(['is_active' => false]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();

    (new DeliverScheduledAlert($rule->id, $channel->id, 'Daily summary', 'body'))->handle(app(AppriseApiClient::class));

    Http::assertNothingSent();
    $log = DeliveryLog::sole();
    expect($log->status)->toBe('skipped');
});

test('a missing rule sends nothing and logs a warning', function () {
    Http::fake();
    Log::spy();
    $channel = NotificationChannel::factory()->create();

    (new DeliverScheduledAlert(999999, $channel->id, 'title', 'body'))->handle(app(AppriseApiClient::class));

    Http::assertNothingSent();
    expect(DeliveryLog::count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()
        ->withArgs(fn ($message, $context) => $context['rule_id'] === 999999 && $context['channel_id'] === $channel->id);
});

test('a missing channel sends nothing and logs a warning', function () {
    Http::fake();
    Log::spy();
    $rule = AlertRule::factory()->dailySummary()->create();

    (new DeliverScheduledAlert($rule->id, 999999, 'title', 'body'))->handle(app(AppriseApiClient::class));

    Http::assertNothingSent();
    expect(DeliveryLog::count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()
        ->withArgs(fn ($message, $context) => $context['rule_id'] === $rule->id && $context['channel_id'] === 999999);
});
