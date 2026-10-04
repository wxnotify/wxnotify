<?php

use App\Enums\ActivityAlertType;
use App\Jobs\SendActivityAlert;
use App\Models\ActivityAlertRule;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Support\Apprise\AppriseApiClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

test('it sends an apprise notification for an active rule', function () {
    Http::fake();

    $rule = ActivityAlertRule::factory()->create([
        'apprise_target' => 'slack://token/botname/channel',
        'is_active' => true,
    ]);
    $user = User::factory()->create(['email' => 'subscriber@example.com']);
    $occurredAt = Carbon::parse('2026-09-05 12:00:00');

    (new SendActivityAlert($rule->id, ActivityAlertType::SignedIn, $user->id, $occurredAt))->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => $request['urls'] === 'slack://token/botname/channel'
        && str_contains($request['body'], 'Signed in: subscriber@example.com')
        && $request['title'] === 'WxNotify Activity Alert');
});

test('the title is prefixed when the message prefix is enabled', function () {
    Http::fake();
    $settings = app(GeneralSettings::class);
    $settings->message_prefix_enabled = true;
    $settings->message_prefix = '[STAGING]';
    $settings->save();

    $rule = ActivityAlertRule::factory()->create([
        'apprise_target' => 'slack://token/botname/channel',
        'is_active' => true,
    ]);
    $user = User::factory()->create();

    (new SendActivityAlert($rule->id, ActivityAlertType::SignedIn, $user->id, now()))->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => $request['title'] === '[STAGING] WxNotify Activity Alert');
});

test('it does nothing if the rule no longer exists', function () {
    Http::fake();

    $user = User::factory()->create();

    (new SendActivityAlert(999999, ActivityAlertType::SignedIn, $user->id, now()))->handle(app(AppriseApiClient::class));

    Http::assertNothingSent();
});

test('it does nothing if the user no longer exists', function () {
    Http::fake();

    $rule = ActivityAlertRule::factory()->create();

    (new SendActivityAlert($rule->id, ActivityAlertType::SignedIn, 999999, now()))->handle(app(AppriseApiClient::class));

    Http::assertNothingSent();
});

test('it does nothing if the rule has since been deactivated', function () {
    Http::fake();

    $rule = ActivityAlertRule::factory()->create(['is_active' => false]);
    $user = User::factory()->create();

    (new SendActivityAlert($rule->id, ActivityAlertType::SignedIn, $user->id, now()))->handle(app(AppriseApiClient::class));

    Http::assertNothingSent();
});

test('it throws when apprise reports the notification was not delivered', function () {
    // A malformed/misconfigured target is reported by Apprise itself as a
    // "success" via a 204 with no body — AppriseApiClient::notify() only
    // treats an exact 200 as delivered, so a 204 is the documented trigger
    // for its false return.
    Http::fake(['*' => Http::response(status: 204)]);

    $rule = ActivityAlertRule::factory()->create(['is_active' => true]);
    $user = User::factory()->create();

    (new SendActivityAlert($rule->id, ActivityAlertType::SignedIn, $user->id, now()))->handle(app(AppriseApiClient::class));
})->throws(RuntimeException::class);

test('failed() logs the exhausted-retries error with structured context', function () {
    Log::spy();

    $rule = ActivityAlertRule::factory()->create();
    $user = User::factory()->create();
    $job = new SendActivityAlert($rule->id, ActivityAlertType::SignedIn, $user->id, now());

    $job->failed(new Exception('connection refused'));

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn ($message, $context) => $context['rule_id'] === $rule->id
            && $context['user_id'] === $user->id
            && $context['exception'] === 'connection refused');
});

test('the message names the client IP when the event came from a request', function () {
    Http::fake();
    $rule = ActivityAlertRule::factory()->create(['apprise_target' => 'slack://token/botname/channel', 'is_active' => true]);
    $user = User::factory()->create(['email' => 'subscriber@example.com']);

    (new SendActivityAlert($rule->id, ActivityAlertType::SignedIn, $user->id, Carbon::parse('2026-09-05 12:00:00'), '203.0.113.9'))->handle(app(AppriseApiClient::class));

    Http::assertSent(fn ($request) => $request['body'] === 'Signed in: subscriber@example.com at Sat, Sep 5, 2026 12:00 PM UTC from 203.0.113.9');
});

test('the message has no IP part when there was no request, and a job queued before this field existed still runs', function () {
    Http::fake();
    $rule = ActivityAlertRule::factory()->create(['apprise_target' => 'slack://token/botname/channel', 'is_active' => true]);
    $user = User::factory()->create(['email' => 'subscriber@example.com']);

    (new SendActivityAlert($rule->id, ActivityAlertType::SignedIn, $user->id, Carbon::parse('2026-09-05 12:00:00')))->handle(app(AppriseApiClient::class));

    // A payload serialized before ipAddress existed unserializes with it uninitialized, bypassing the constructor.
    $legacy = (new ReflectionClass(SendActivityAlert::class))->newInstanceWithoutConstructor();
    foreach (['ruleId' => $rule->id, 'type' => ActivityAlertType::SignedIn, 'userId' => $user->id, 'occurredAt' => Carbon::parse('2026-09-05 12:00:00')] as $property => $value) {
        (new ReflectionProperty(SendActivityAlert::class, $property))->setValue($legacy, $value);
    }
    $legacy->handle(app(AppriseApiClient::class));

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request['body'] === 'Signed in: subscriber@example.com at Sat, Sep 5, 2026 12:00 PM UTC');
});
