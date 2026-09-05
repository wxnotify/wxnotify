<?php

use App\Enums\ActivityAlertType;
use App\Jobs\SendActivityAlert;
use App\Models\ActivityAlertRule;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('changing a user\'s name dispatches NameChanged', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::NameChanged->value],
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $user->update(['name' => 'New Name']);

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::NameChanged
        && $job->userId === $user->id);
});

test('changing a user\'s email dispatches EmailChanged', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::EmailChanged->value],
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $user->update(['email' => 'new@example.com']);

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::EmailChanged);
});

test('changing a user\'s password dispatches PasswordChanged', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::PasswordChanged->value],
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $user->update(['password' => 'a-new-strong-password']);

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::PasswordChanged);
});

test('changing the app authentication secret dispatches MfaChanged', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::MfaChanged->value],
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $user->forceFill(['app_authentication_secret' => 'a-new-secret'])->save();

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::MfaChanged);
});

test('changing two fields at once dispatches both types', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::NameChanged->value, ActivityAlertType::EmailChanged->value],
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $user->update(['name' => 'New Name', 'email' => 'new2@example.com']);

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::NameChanged);
    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::EmailChanged);
});

test('deleting a user dispatches AccountDeleted', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::AccountDeleted->value],
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $userId = $user->id;
    $user->delete();

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::AccountDeleted
        && $job->userId === $userId);
});

test('an unrelated field change dispatches nothing, even when every type is configured', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => array_column(ActivityAlertType::cases(), 'value'),
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $user->update(['is_staff' => true]);

    Queue::assertNotPushed(SendActivityAlert::class);
});
