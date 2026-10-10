<?php

use App\Actions\Channels\DeleteChannel;
use App\Actions\Channels\RenameChannel;
use App\Exceptions\ChannelInUse;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->channel = NotificationChannel::factory()->for($this->user)->create(['label' => 'Old']);
});

test('renaming changes the label and logs the subscriber as the causer', function () {
    app(RenameChannel::class)($this->user, $this->channel, 'New');

    expect($this->channel->fresh()->label)->toBe('New')
        ->and(Activity::query()->where('subject_type', NotificationChannel::class)->latest('id')->first()->causer_id)->toBe($this->user->id);
});

test('a channel that is an alert\'s only one cannot be deleted, and the alert is named', function () {
    $rule = AlertRule::factory()->for($this->user)->create(['name' => 'Storm warnings']);
    $rule->notificationChannels()->attach($this->channel);

    try {
        app(DeleteChannel::class)($this->user, $this->channel);
        $this->fail('Expected ChannelInUse');
    } catch (ChannelInUse $e) {
        expect($e->alertNames)->toBe(['Storm warnings']);
    }

    expect(NotificationChannel::find($this->channel->id))->not->toBeNull();
});

test('a channel shared with another channel on every alert it serves is deleted', function () {
    $rule = AlertRule::factory()->for($this->user)->create();
    $other = NotificationChannel::factory()->for($this->user)->create();
    $rule->notificationChannels()->attach([$this->channel->id, $other->id]);

    app(DeleteChannel::class)($this->user, $this->channel);

    expect(NotificationChannel::find($this->channel->id))->toBeNull();
});
