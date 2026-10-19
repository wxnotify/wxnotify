<?php

use App\Jobs\RecordQueueHeartbeat;
use App\Support\Setup\QueueHeartbeat;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

test('lastRanAt is null before the first run', function () {
    expect(QueueHeartbeat::lastRanAt())->toBeNull();
});

test('the job records the time it ran', function () {
    $this->travelTo(now()->startOfSecond());
    $ranAt = now();

    app()->call([new RecordQueueHeartbeat, 'handle']);

    expect(QueueHeartbeat::lastRanAt()?->equalTo($ranAt))->toBeTrue();
});

test('the job is scheduled every minute', function () {
    Artisan::call('schedule:list'); // boots the console application, which registers the schedule

    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains($event->description ?? $event->getSummaryForDisplay(), RecordQueueHeartbeat::class));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *');
});
