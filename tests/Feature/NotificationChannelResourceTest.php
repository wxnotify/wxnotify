<?php

use App\Filament\Dashboard\Resources\NotificationChannels\Pages\ListNotificationChannels;
use App\Models\NotificationChannel;
use App\Models\User;
use Filament\Actions\Testing\TestAction;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('entering the correct code on an unverified channel verifies it', function () {
    $channel = NotificationChannel::factory()->for($this->user)->unverified()->create();

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('verify')->table($channel), data: [
            'code' => $channel->confirmation_code,
        ])
        ->assertNotified();

    expect($channel->fresh()->isVerified())->toBeTrue();
});

test('entering the wrong code does not verify the channel', function () {
    $channel = NotificationChannel::factory()->for($this->user)->unverified()->create();

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('verify')->table($channel), data: [
            'code' => 'wrong-code',
        ])
        ->assertNotified();

    expect($channel->fresh()->isVerified())->toBeFalse();
});

test('entering an expired code does not verify the channel', function () {
    $channel = NotificationChannel::factory()->for($this->user)->unverified()->create([
        'confirmation_code_expires_at' => now()->subMinute(),
    ]);

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make('verify')->table($channel), data: [
            'code' => $channel->confirmation_code,
        ])
        ->assertNotified();

    expect($channel->fresh()->isVerified())->toBeFalse();
});

test('the verify action is not visible for an already-verified channel', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create();

    livewire(ListNotificationChannels::class)
        ->assertActionHidden(TestAction::make('verify')->table($channel));
});

test('the verify action is visible for an unverified channel', function () {
    $channel = NotificationChannel::factory()->for($this->user)->unverified()->create();

    livewire(ListNotificationChannels::class)
        ->assertActionVisible(TestAction::make('verify')->table($channel));
});
