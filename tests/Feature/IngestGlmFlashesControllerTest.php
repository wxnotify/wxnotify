<?php

use App\Jobs\DeliverScheduledAlert;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    config(['services.pipeline.webhook_token' => 'test-token']);
});

test('a request without the bearer token is rejected', function () {
    $this->postJson('/api/pipeline/glm-flashes', ['flashes' => []])
        ->assertUnauthorized();
});

test('a valid request matches the flashes and returns no content', function () {
    Bus::fake();

    $user = User::factory()->create();
    $location = Location::factory()->for($user)->create(['lat' => 39.0, 'lng' => -95.0]);
    $rule = AlertRule::factory()->lightning()->for($user)->for($location)->create(['radius_miles' => 15]);
    $channel = NotificationChannel::factory()->for($user)->create();
    $rule->notificationChannels()->attach($channel);

    $this->withHeader('Authorization', 'Bearer test-token')
        ->postJson('/api/pipeline/glm-flashes', [
            'flashes' => [['id' => '1', 'lat' => 39.1, 'lon' => -95.0]],
        ])
        ->assertNoContent();

    Bus::assertDispatched(DeliverScheduledAlert::class);
});

test('an empty flashes array is accepted and matches nothing', function () {
    Bus::fake();

    $this->withHeader('Authorization', 'Bearer test-token')
        ->postJson('/api/pipeline/glm-flashes', ['flashes' => []])
        ->assertNoContent();

    Bus::assertNothingDispatched();
});

test('a malformed flash is rejected by validation', function () {
    $this->withHeader('Authorization', 'Bearer test-token')
        ->postJson('/api/pipeline/glm-flashes', [
            'flashes' => [['id' => '1', 'lat' => 200.0, 'lon' => -95.0]],
        ])
        ->assertUnprocessable();
});
