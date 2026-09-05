<?php

use App\Enums\ActivityAlertType;
use App\Jobs\SendActivityAlert;
use App\Models\ActivityAlertRule;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Queue;

test('logging in dispatches SignedIn', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::SignedIn->value],
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    event(new Login('web', $user, false));

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::SignedIn
        && $job->userId === $user->id);
});

test('logging out dispatches SignedOut', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::SignedOut->value],
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    event(new Logout('web', $user));

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::SignedOut);
});

test('a logout event with no authenticated user dispatches nothing', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::SignedOut->value],
        'is_active' => true,
    ]);

    event(new Logout('web', null));

    Queue::assertNotPushed(SendActivityAlert::class);
});
