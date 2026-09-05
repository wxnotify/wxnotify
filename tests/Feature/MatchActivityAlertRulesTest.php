<?php

use App\Actions\Visibility\MatchActivityAlertRules;
use App\Enums\ActivityAlertType;
use App\Jobs\SendActivityAlert;
use App\Models\ActivityAlertRule;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('an all_subscribers active rule matching the type dispatches a job for any user', function () {
    Queue::fake();

    $rule = ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::SignedIn->value],
        'is_active' => true,
    ]);
    $user = User::factory()->create();

    (new MatchActivityAlertRules)($user, ActivityAlertType::SignedIn);

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->ruleId === $rule->id && $job->userId === $user->id);
});

test('a specific_users rule only matches a pivoted user', function () {
    Queue::fake();

    $watched = User::factory()->create();
    $other = User::factory()->create();
    $rule = ActivityAlertRule::factory()->create([
        'scope' => 'specific_users',
        'activity_types' => [ActivityAlertType::SignedIn->value],
        'is_active' => true,
    ]);
    $rule->users()->attach($watched);

    (new MatchActivityAlertRules)($watched, ActivityAlertType::SignedIn);
    (new MatchActivityAlertRules)($other, ActivityAlertType::SignedIn);

    Queue::assertPushed(SendActivityAlert::class, 1);
});

test('an inactive rule never matches', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::SignedIn->value],
        'is_active' => false,
    ]);
    $user = User::factory()->create();

    (new MatchActivityAlertRules)($user, ActivityAlertType::SignedIn);

    Queue::assertNotPushed(SendActivityAlert::class);
});

test('a rule not covering the given type never matches', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::SignedOut->value],
        'is_active' => true,
    ]);
    $user = User::factory()->create();

    (new MatchActivityAlertRules)($user, ActivityAlertType::SignedIn);

    Queue::assertNotPushed(SendActivityAlert::class);
});
