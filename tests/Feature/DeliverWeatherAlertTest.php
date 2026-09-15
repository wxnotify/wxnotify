<?php

use App\Enums\Tier;
use App\Jobs\DeliverWeatherAlert;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use App\Support\Apprise\AppriseApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

test('a missing rule sends nothing and logs a warning, since no channel_type is available for a DeliveryLog row', function () {
    Http::fake();
    Log::spy();
    $channel = NotificationChannel::factory()->create();
    $product = IngestedProduct::factory()->create();

    (new DeliverWeatherAlert(999999, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertNothingSent();
    expect(DeliveryLog::count())->toBe(0);
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($message, $context) => $context['rule_id'] === 999999 && $context['channel_id'] === $channel->id);
});

test('a missing channel sends nothing and logs a warning, since no channel_type is available for a DeliveryLog row', function () {
    Http::fake();
    Log::spy();
    $rule = AlertRule::factory()->create();
    $product = IngestedProduct::factory()->create();

    (new DeliverWeatherAlert($rule->id, 999999, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertNothingSent();
    expect(DeliveryLog::count())->toBe(0);
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($message, $context) => $context['rule_id'] === $rule->id && $context['channel_id'] === 999999);
});

test('a missing product sends nothing but writes a skipped DeliveryLog row', function () {
    Http::fake();
    $rule = AlertRule::factory()->create();
    $channel = NotificationChannel::factory()->for($rule->user)->create();

    (new DeliverWeatherAlert($rule->id, $channel->id, 999999))->handle(app(AppriseApiClient::class));

    Http::assertNothingSent();
    $log = DeliveryLog::sole();
    expect($log->status)->toBe('skipped')
        ->and($log->error_detail)->not->toBeNull()
        ->and($log->alert_rule_id)->toBe($rule->id)
        ->and($log->notification_channel_id)->toBe($channel->id)
        ->and($log->channel_type)->toBe($channel->channel_type);
});

test('a deactivated rule sends nothing but writes a skipped DeliveryLog row', function () {
    Http::fake();
    $rule = AlertRule::factory()->create(['is_active' => false]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $product = IngestedProduct::factory()->create();

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertNothingSent();
    $log = DeliveryLog::sole();
    expect($log->status)->toBe('skipped')
        ->and($log->error_detail)->not->toBeNull()
        ->and($log->alert_rule_id)->toBe($rule->id)
        ->and($log->notification_channel_id)->toBe($channel->id)
        ->and($log->channel_type)->toBe($channel->channel_type);
});

test('a custom subject template is used as the Apprise title instead of displayName()', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $product = Product::factory()->create(['name' => 'Tornado Warning']);
    $rule = AlertRule::factory()->for($plusUser)->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
        'custom_subject_template' => 'Alert: {product}',
    ]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $ingested = IngestedProduct::factory()->create(['product_id' => $product->id]);

    (new DeliverWeatherAlert($rule->id, $channel->id, $ingested->id))->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => $request['title'] === 'Alert: Tornado Warning');
});

test('message_body_style Brief truncates the body to 280 characters', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->for($plusUser)->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
        'message_body_style' => 'brief',
    ]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $longText = str_repeat('Tornado Warning issued for the area. ', 30);
    $product = IngestedProduct::factory()->create(['text' => $longText]);

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => strlen($request['body']) <= 280);
});

test('message_body_style Full (or null) preserves today\'s 1500-character behavior', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->for($plusUser)->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
        'message_body_style' => null,
    ]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $longText = str_repeat('Tornado Warning issued for the area. ', 60);
    $product = IngestedProduct::factory()->create(['text' => $longText]);

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => strlen($request['body']) > 280 && strlen($request['body']) <= 1500);
});

test('a Free-tier owner\'s custom subject and Brief style are ignored at delivery', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $freeUser = User::factory()->create(['tier_override' => Tier::Free]);
    $rule = AlertRule::factory()->for($freeUser)->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
        'custom_subject_template' => 'Alert: {product}',
        'message_body_style' => 'brief',
    ]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $longText = str_repeat('Tornado Warning issued for the area. ', 60);
    $product = IngestedProduct::factory()->create(['text' => $longText]);

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => $request['title'] === $rule->displayName() && strlen($request['body']) > 280);
});

test('the default title (no custom template) still reaches Apprise as displayName()', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $product = Product::factory()->create(['name' => 'Tornado Warning']);
    $rule = AlertRule::factory()->for($plusUser)->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
        'custom_subject_template' => null,
    ]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $ingested = IngestedProduct::factory()->create(['product_id' => $product->id]);

    (new DeliverWeatherAlert($rule->id, $channel->id, $ingested->id))->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => $request['title'] === $rule->displayName());
});

test('a CAP-sourced product with no paired text uses its description as the message body', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
    ]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $product = IngestedProduct::factory()->create([
        'format' => 'cap',
        'text' => '<?xml version="1.0"?><alert>raw CAP XML, never meant to reach a subscriber</alert>',
        'description' => 'Flash flooding is ongoing across the warned area. Seek higher ground immediately.',
    ]);

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => $request['body'] === 'Flash flooding is ongoing across the warned area. Seek higher ground immediately.');
});

test('a CAP-sourced product with no description falls back to its raw text rather than crashing', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
    ]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $product = IngestedProduct::factory()->create([
        'format' => 'cap',
        'text' => 'Raw CAP XML content used as the last-resort fallback.',
        'description' => null,
    ]);

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => $request['body'] === 'Raw CAP XML content used as the last-resort fallback.');
});

test('a text-sourced product still uses its raw text as the message body, unaffected by the CAP fallback', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);

    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
    ]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $product = IngestedProduct::factory()->create(['format' => 'text', 'text' => 'Tornado Warning issued for the area.']);

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => $request['body'] === 'Tornado Warning issued for the area.');
});
