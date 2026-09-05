<?php

use App\Enums\ActivityAlertType;
use App\Jobs\SendActivityAlert;
use App\Models\ActivityAlertRule;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

test('it sends an apprise notification for an active rule', function () {
    Http::fake();

    $rule = ActivityAlertRule::factory()->create([
        'apprise_target' => 'slack://token/botname/channel',
        'is_active' => true,
    ]);
    $user = User::factory()->create(['email' => 'subscriber@example.com']);
    $occurredAt = Carbon::parse('2026-09-05 12:00:00');

    (new SendActivityAlert($rule->id, ActivityAlertType::SignedIn, $user->id, $occurredAt))->handle();

    Http::assertSent(fn ($request) => $request['urls'] === 'slack://token/botname/channel'
        && str_contains($request['body'], 'Signed in: subscriber@example.com')
        && $request['title'] === 'WxNotify Activity Alert');
});

test('it does nothing if the rule no longer exists', function () {
    Http::fake();

    $user = User::factory()->create();

    (new SendActivityAlert(999999, ActivityAlertType::SignedIn, $user->id, now()))->handle();

    Http::assertNothingSent();
});

test('it does nothing if the user no longer exists', function () {
    Http::fake();

    $rule = ActivityAlertRule::factory()->create();

    (new SendActivityAlert($rule->id, ActivityAlertType::SignedIn, 999999, now()))->handle();

    Http::assertNothingSent();
});

test('it does nothing if the rule has since been deactivated', function () {
    Http::fake();

    $rule = ActivityAlertRule::factory()->create(['is_active' => false]);
    $user = User::factory()->create();

    (new SendActivityAlert($rule->id, ActivityAlertType::SignedIn, $user->id, now()))->handle();

    Http::assertNothingSent();
});
