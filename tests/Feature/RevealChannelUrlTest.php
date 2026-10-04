<?php

use App\Actions\Channels\RevealChannelUrl;
use App\Models\NotificationChannel;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use STS\FilamentImpersonate\ImpersonateManager;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->channel = NotificationChannel::factory()->for($this->user)->create([
        'channel_type' => 'pushover',
        'label' => 'Work Pushover',
        'apprise_url' => 'pover://uKey1234567890@aTok0987654321',
    ]);
});

test('the owner gets the exact decrypted URL back', function () {
    expect((new RevealChannelUrl)($this->channel))->toBe(['url' => 'pover://uKey1234567890@aTok0987654321', 'error' => null]);
});

test('a reveal is recorded on the channel, by whom and when, never with the URL', function () {
    (new RevealChannelUrl)($this->channel);

    $entry = Activity::where('subject_type', NotificationChannel::class)->where('subject_id', $this->channel->id)->where('event', 'revealed')->sole();

    expect($entry->causer_id)->toBe($this->user->id)
        ->and($entry->description)->toBe('Revealed the channel destination URL')
        ->and(json_encode($entry->toArray()))->not->toContain('uKey1234567890')->not->toContain('aTok0987654321');
});

test('staff impersonating the subscriber are refused, get no URL, and leave no log entry', function () {
    $this->mock(ImpersonateManager::class, fn ($mock) => $mock->shouldReceive('isImpersonating')->andReturn(true));

    expect((new RevealChannelUrl)($this->channel))->toBe(['url' => null, 'error' => 'Not available while impersonating a subscriber.'])
        ->and(Activity::where('event', 'revealed')->exists())->toBeFalse();
});

test('another subscriber\'s channel is refused', function () {
    $stranger = NotificationChannel::factory()->for(User::factory())->create(['apprise_url' => 'discord://123456789/theirtoken/']);

    expect((new RevealChannelUrl)($stranger))->toBe(['url' => null, 'error' => 'You can only reveal your own channels.'])
        ->and(Activity::where('event', 'revealed')->exists())->toBeFalse();
});

test('a channel with no Apprise URL has nothing to reveal', function () {
    $email = NotificationChannel::factory()->for($this->user)->email()->create();

    expect((new RevealChannelUrl)($email))->toBe(['url' => null, 'error' => 'This channel has no URL to reveal.'])
        ->and(Activity::where('event', 'revealed')->exists())->toBeFalse();
});
