<?php

use App\Jobs\DeliverWeatherAlert;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Support\Apprise\AppriseApiClient;
use Illuminate\Support\Facades\Http;

test('it delivers via apprise and writes a success DeliveryLog row', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $channel = NotificationChannel::factory()->for($rule->user)->create(['apprise_url' => 'discord://token/id']);
    $product = IngestedProduct::factory()->create(['text' => 'Tornado Warning issued for...']);

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => $request['urls'] === 'discord://token/id');

    $log = DeliveryLog::sole();
    expect($log->status)->toBe('success')
        ->and($log->error_detail)->toBeNull()
        ->and($log->alert_rule_id)->toBe($rule->id)
        ->and($log->notification_channel_id)->toBe($channel->id)
        ->and($log->channel_type)->toBe($channel->channel_type);
});

test('a non-delivery from apprise writes a failure DeliveryLog row instead of throwing', function () {
    Http::fake(['*/notify/*' => Http::response(status: 204)]);

    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $product = IngestedProduct::factory()->create();

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    $log = DeliveryLog::sole();
    expect($log->status)->toBe('failure')
        ->and($log->error_detail)->not->toBeNull();
});

test('it does nothing if the rule no longer exists', function () {
    Http::fake();
    $channel = NotificationChannel::factory()->create();
    $product = IngestedProduct::factory()->create();

    (new DeliverWeatherAlert(999999, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertNothingSent();
    expect(DeliveryLog::count())->toBe(0);
});

test('it does nothing if the channel no longer exists', function () {
    Http::fake();
    $rule = AlertRule::factory()->create();
    $product = IngestedProduct::factory()->create();

    (new DeliverWeatherAlert($rule->id, 999999, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertNothingSent();
});

test('it does nothing if the product no longer exists', function () {
    Http::fake();
    $rule = AlertRule::factory()->create();
    $channel = NotificationChannel::factory()->for($rule->user)->create();

    (new DeliverWeatherAlert($rule->id, $channel->id, 999999))->handle(app(AppriseApiClient::class));

    Http::assertNothingSent();
});

test('it does nothing if the rule has since been deactivated', function () {
    Http::fake();
    $rule = AlertRule::factory()->create(['is_active' => false]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $product = IngestedProduct::factory()->create();

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertNothingSent();
});
