<?php

use App\Jobs\DeliverScheduledAlert;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 16:53:00'); // 10:53 America/Denver

    $this->location = Location::factory()->create(['timezone' => 'America/Denver', 'lat' => 40.0031694, 'lng' => -104.997011]);
    $this->rule = AlertRule::factory()->dailySummary()->for($this->location->user)->for($this->location)->create([
        'delivery_time' => '08:40',
        'last_scheduled_run_on' => '2026-10-02', // already delivered this morning
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('moving the delivery time to later today lets the rule run again today', function () {
    $this->rule->update(['delivery_time' => '11:00']);

    expect($this->rule->fresh()->last_scheduled_run_on)->toBeNull();
});

test('a new delivery time that has already passed today does not re-send retroactively', function () {
    $this->rule->update(['delivery_time' => '09:15']);

    expect($this->rule->fresh()->last_scheduled_run_on->toDateString())->toBe('2026-10-02');
});

test('an update that leaves the delivery time alone keeps the run stamp', function () {
    $this->rule->update(['name' => 'Morning summary']);

    expect($this->rule->fresh()->last_scheduled_run_on->toDateString())->toBe('2026-10-02');
});

test('re-saving the same delivery time in a different format is not a change', function () {
    $this->rule->update(['delivery_time' => '08:40:00']);

    expect($this->rule->fresh()->last_scheduled_run_on->toDateString())->toBe('2026-10-02');
});

test('the dispatcher stamping its own run is not undone by the reset', function () {
    $this->rule->update(['delivery_time' => '11:00']);
    $this->rule->forceFill(['last_scheduled_run_on' => '2026-10-02', 'delivery_time' => '11:00'])->save();

    expect($this->rule->fresh()->last_scheduled_run_on->toDateString())->toBe('2026-10-02');
});

test('the location timezone decides what later today means', function () {
    // 16:53 UTC is 06:53 in Honolulu, so 08:00 there is still ahead; 06:00 is not.
    $honolulu = Location::factory()->create(['timezone' => 'Pacific/Honolulu']);
    $rule = AlertRule::factory()->dailySummary()->for($honolulu->user)->for($honolulu)->create([
        'delivery_time' => '05:00', 'last_scheduled_run_on' => '2026-10-02',
    ]);

    $rule->update(['delivery_time' => '06:00']);
    expect($rule->fresh()->last_scheduled_run_on)->not->toBeNull();

    $rule->update(['delivery_time' => '08:00']);
    expect($rule->fresh()->last_scheduled_run_on)->toBeNull();
});

test('after rescheduling to later today the dispatcher delivers at the new time', function () {
    Bus::fake();
    nwsFakeEverything();
    $channel = NotificationChannel::factory()->for($this->rule->user)->email('a@example.com')->create();
    $this->rule->notificationChannels()->attach($channel);

    $this->rule->update(['delivery_time' => '11:00']);

    // 10:53 local: the new time has not arrived yet.
    $this->artisan('alerts:dispatch-scheduled')->assertSuccessful();
    Bus::assertNotDispatched(DeliverScheduledAlert::class);

    Carbon::setTestNow('2026-10-02 17:00:00'); // 11:00 America/Denver
    $this->artisan('alerts:dispatch-scheduled')->assertSuccessful();

    Bus::assertDispatched(DeliverScheduledAlert::class, fn ($job) => $job->ruleId === $this->rule->id);
});
