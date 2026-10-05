<?php

use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;

test('the scheduled alert dispatch cannot overlap itself', function () {
    Artisan::call('schedule:list'); // boots the console application, which registers the schedule

    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains($event->command, 'alerts:dispatch-scheduled'));

    expect($event)->not->toBeNull()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(30);
});

test('expired app sign-ins are pruned daily so their device channels go with them', function () {
    Artisan::call('schedule:list');

    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains($event->command, 'sanctum:prune-expired'));

    expect($event)->not->toBeNull()
        ->and($event->command)->toContain('--hours=24')
        ->and($event->expression)->toBe('0 0 * * *');
});

test('pruning expired tokens removes an expired app token and its device channel but keeps a live one', function () {
    $user = User::factory()->create();
    $expired = $user->createToken('Old phone', ['app'], now()->subDays(3))->accessToken;
    $live = $user->createToken('New phone', ['app'], now()->addDays(30))->accessToken;
    $expiredChannel = NotificationChannel::factory()->for($user)->mobilePush('token-old')->create(['personal_access_token_id' => $expired->id]);
    $liveChannel = NotificationChannel::factory()->for($user)->mobilePush('token-new')->create(['personal_access_token_id' => $live->id]);

    Artisan::call('sanctum:prune-expired', ['--hours' => 24]);

    expect(PersonalAccessToken::find($expired->id))->toBeNull()
        ->and(NotificationChannel::find($expiredChannel->id))->toBeNull()
        ->and(PersonalAccessToken::find($live->id))->not->toBeNull()
        ->and(NotificationChannel::find($liveChannel->id))->not->toBeNull();
});
