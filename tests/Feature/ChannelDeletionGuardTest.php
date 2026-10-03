<?php

use App\Filament\Dashboard\Resources\NotificationChannels\Pages\ListNotificationChannels;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelDeletionGuard;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/**
 * A Daily Summary alert for the user, sending to exactly the given channels.
 *
 * @param  array<int, NotificationChannel>  $channels
 */
function alertSendingTo(User $user, array $channels, string $name = 'Morning summary'): AlertRule
{
    $alert = AlertRule::factory()->dailySummary()->for($user)->for(Location::factory()->for($user)->create())->create(['name' => $name]);
    $alert->notificationChannels()->attach(collect($channels)->pluck('id'));

    return $alert;
}

test('an alert whose only channel is this one is left without a channel', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create();
    $alert = alertSendingTo($this->user, [$channel]);

    expect(ChannelDeletionGuard::alertsLeftWithoutChannel($channel)->pluck('id')->all())->toBe([$alert->id]);
});

test('an alert that also has another channel is not blocked, but is counted as affected', function () {
    $old = NotificationChannel::factory()->for($this->user)->create();
    $other = NotificationChannel::factory()->for($this->user)->create();
    alertSendingTo($this->user, [$old, $other]);
    alertSendingTo($this->user, [$old, $other], 'Storm warnings');

    expect(ChannelDeletionGuard::alertsLeftWithoutChannel($old))->toBeEmpty()
        ->and(ChannelDeletionGuard::alertsKeepingOtherChannels($old))->toBe(2);
});

test('a channel no alert uses blocks nothing', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create();
    alertSendingTo($this->user, [NotificationChannel::factory()->for($this->user)->create()]);

    expect(ChannelDeletionGuard::alertsLeftWithoutChannel($channel))->toBeEmpty()
        ->and(ChannelDeletionGuard::alertsKeepingOtherChannels($channel))->toBe(0);
});

test('only the blocked alerts are returned when a channel is shared', function () {
    $shared = NotificationChannel::factory()->for($this->user)->create();
    $spare = NotificationChannel::factory()->for($this->user)->create();
    $sole = alertSendingTo($this->user, [$shared], 'Only on shared');
    alertSendingTo($this->user, [$shared, $spare], 'Also on spare');

    expect(ChannelDeletionGuard::alertsLeftWithoutChannel($shared)->pluck('id')->all())->toBe([$sole->id])
        ->and(ChannelDeletionGuard::alertsKeepingOtherChannels($shared))->toBe(1);
});

test('another subscriber\'s alerts never matter', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create();
    $stranger = User::factory()->create();
    alertSendingTo($stranger, [NotificationChannel::factory()->for($stranger)->create()]);

    expect(ChannelDeletionGuard::alertsLeftWithoutChannel($channel))->toBeEmpty();
});

test('a channel that is an alert\'s only one cannot be deleted from the table', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create(['label' => 'My Discord']);
    alertSendingTo($this->user, [$channel], 'Morning summary');

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($channel))
        ->assertNotified("Can't delete My Discord");

    expect(NotificationChannel::find($channel->id))->not->toBeNull();
});

test('the refusal names the alerts and says what to do', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create(['label' => 'My Discord']);
    alertSendingTo($this->user, [$channel], 'Morning summary');

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($channel));

    expect(sentNotificationBodies())->toContain('only channel')->toContain('Morning summary')->toContain('another channel');
});

test('a channel shared with other channels on every alert is deleted and detached', function () {
    $old = NotificationChannel::factory()->for($this->user)->create();
    $other = NotificationChannel::factory()->for($this->user)->create();
    $alert = alertSendingTo($this->user, [$old, $other]);

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($old))
        ->assertNotified();

    expect(NotificationChannel::find($old->id))->toBeNull()
        ->and($alert->fresh()->notificationChannels->pluck('id')->all())->toBe([$other->id]);
});

test('an unused channel is deleted as before', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create();

    livewire(ListNotificationChannels::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($channel))
        ->assertNotified();

    expect(NotificationChannel::find($channel->id))->toBeNull();
});

test('the confirmation says how many other alerts the channel will be removed from', function () {
    $old = NotificationChannel::factory()->for($this->user)->create();
    $other = NotificationChannel::factory()->for($this->user)->create();
    alertSendingTo($this->user, [$old, $other]);
    alertSendingTo($this->user, [$old, $other], 'Storm warnings');

    $description = livewire(ListNotificationChannels::class)
        ->mountAction(TestAction::make(DeleteAction::class)->table($old))
        ->instance()
        ->getMountedAction()
        ->getModalDescription();

    expect((string) $description)->toContain('removed from 2 other alerts');
});

test('the confirmation uses the singular for a single other alert', function () {
    $old = NotificationChannel::factory()->for($this->user)->create();
    alertSendingTo($this->user, [$old, NotificationChannel::factory()->for($this->user)->create()]);

    $description = livewire(ListNotificationChannels::class)
        ->mountAction(TestAction::make(DeleteAction::class)->table($old))
        ->instance()
        ->getMountedAction()
        ->getModalDescription();

    expect((string) $description)->toContain('removed from 1 other alert;')->toContain('that alert keeps');
});

test('the confirmation keeps its default wording when no other alert is affected', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create();

    $description = livewire(ListNotificationChannels::class)
        ->mountAction(TestAction::make(DeleteAction::class)->table($channel))
        ->instance()
        ->getMountedAction()
        ->getModalDescription();

    expect((string) $description)->not->toContain('removed from');
});

test('bulk delete removes channels that are safe, keeps ones that would strand an alert, and says which', function () {
    $sole = NotificationChannel::factory()->for($this->user)->create(['label' => '<b>Sole</b>']);
    $free = NotificationChannel::factory()->for($this->user)->create();
    alertSendingTo($this->user, [$sole], 'Morning summary');

    livewire(ListNotificationChannels::class)
        ->selectTableRecords([$sole->id, $free->id])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

    $body = sentNotificationBodies();

    expect(NotificationChannel::find($sole->id))->not->toBeNull()
        ->and(NotificationChannel::find($free->id))->toBeNull()
        ->and($body)->toContain('Morning summary')
        ->toContain('&lt;b&gt;Sole&lt;/b&gt;')
        ->not->toContain('<b>Sole</b>');
});

test('selecting every channel of an alert in a bulk delete never strands it', function () {
    $a = NotificationChannel::factory()->for($this->user)->create(['label' => 'A']);
    $b = NotificationChannel::factory()->for($this->user)->create(['label' => 'B']);
    $alert = alertSendingTo($this->user, [$a, $b]);

    livewire(ListNotificationChannels::class)
        ->selectTableRecords([$a->id, $b->id])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

    expect($alert->fresh()->notificationChannels)->toHaveCount(1)
        ->and(NotificationChannel::whereIn('id', [$a->id, $b->id])->count())->toBe(1);
});
