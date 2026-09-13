<?php

use App\Enums\ActivityAlertType;
use App\Jobs\SendActivityAlert;
use App\Models\ActivityAlertRule;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use Illuminate\Support\Facades\Queue;

test('creating an alert rule dispatches AlertCreated for its owner', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::AlertCreated->value],
        'is_active' => true,
    ]);

    $alertRule = AlertRule::factory()->create();

    Queue::assertPushed(SendActivityAlert::class, 1);
    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::AlertCreated
        && $job->userId === $alertRule->user_id);
});

test('updating an alert rule dispatches AlertUpdated', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::AlertUpdated->value],
        'is_active' => true,
    ]);

    $alertRule = AlertRule::factory()->create();
    $alertRule->update(['name' => 'New name']);

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::AlertUpdated
        && $job->userId === $alertRule->user_id);
});

test('deleting an alert rule dispatches AlertDeleted, resolving the owner from the deletion snapshot', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [ActivityAlertType::AlertDeleted->value],
        'is_active' => true,
    ]);

    $alertRule = AlertRule::factory()->create();
    $ownerId = $alertRule->user_id;
    $alertRule->delete();

    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::AlertDeleted
        && $job->userId === $ownerId);
});

test('creating, updating, and deleting a location dispatch the matching Location types', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [
            ActivityAlertType::LocationCreated->value,
            ActivityAlertType::LocationUpdated->value,
            ActivityAlertType::LocationDeleted->value,
        ],
        'is_active' => true,
    ]);

    $location = Location::factory()->create();
    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::LocationCreated);

    $location->update(['label' => 'New label']);
    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::LocationUpdated);

    $ownerId = $location->user_id;
    $location->delete();
    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::LocationDeleted
        && $job->userId === $ownerId);
});

test('creating and deleting a notification channel dispatch the matching Channel types', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [
            ActivityAlertType::ChannelCreated->value,
            ActivityAlertType::ChannelDeleted->value,
        ],
        'is_active' => true,
    ]);

    $channel = NotificationChannel::factory()->create();
    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::ChannelCreated);

    $ownerId = $channel->user_id;
    $channel->delete();
    Queue::assertPushed(SendActivityAlert::class, fn ($job) => $job->type === ActivityAlertType::ChannelDeleted
        && $job->userId === $ownerId);
});

test('updating a notification channel is not a mapped activity type and dispatches nothing', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => [
            ActivityAlertType::ChannelCreated->value,
            ActivityAlertType::ChannelDeleted->value,
        ],
        'is_active' => true,
    ]);

    $channel = NotificationChannel::factory()->create();
    Queue::fake(); // reset after the create() above already dispatched one

    $channel->update(['label' => 'New label']);

    Queue::assertNotPushed(SendActivityAlert::class);
});

test('activity for an unmapped subject type dispatches nothing, even when every type is configured', function () {
    Queue::fake();

    ActivityAlertRule::factory()->create([
        'scope' => 'all_subscribers',
        'activity_types' => array_column(ActivityAlertType::cases(), 'value'),
        'is_active' => true,
    ]);

    Product::factory()->create();

    Queue::assertNotPushed(SendActivityAlert::class);
});
