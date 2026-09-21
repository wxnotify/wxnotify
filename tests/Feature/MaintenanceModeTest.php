<?php

use App\Exceptions\MaintenanceModeException;
use App\Models\AlertRule;
use App\Models\AlertTemplate;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\TraccarServer;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use STS\FilamentImpersonate\ImpersonateManager;

function setMaintenance(bool $on, ?string $message = null): void
{
    $settings = app(GeneralSettings::class);
    $settings->maintenance_mode = $on;
    $settings->maintenance_message = $message;
    $settings->save();
}

beforeEach(function () {
    $this->withoutVite();
    $this->subscriber = User::factory()->create();
    $this->location = Location::factory()->for($this->subscriber)->create();
});

test('a signed-in subscriber cannot create, update or delete their data during maintenance', function () {
    $this->actingAs($this->subscriber);
    setMaintenance(true);

    expect(fn () => Location::factory()->for($this->subscriber)->create())->toThrow(MaintenanceModeException::class)
        ->and(fn () => $this->location->update(['label' => 'Renamed']))->toThrow(MaintenanceModeException::class)
        ->and(fn () => $this->location->delete())->toThrow(MaintenanceModeException::class);
});

test('every guarded model type is protected', function () {
    $this->actingAs($this->subscriber);
    setMaintenance(true);

    expect(fn () => NotificationChannel::factory()->for($this->subscriber)->create())->toThrow(MaintenanceModeException::class)
        ->and(fn () => AlertRule::factory()->for($this->subscriber)->create(['location_id' => $this->location->id]))->toThrow(MaintenanceModeException::class)
        ->and(fn () => AlertTemplate::factory()->for($this->subscriber)->create())->toThrow(MaintenanceModeException::class)
        ->and(fn () => TraccarServer::factory()->for($this->subscriber)->create())->toThrow(MaintenanceModeException::class);
});

test('the same writes succeed when maintenance is off', function () {
    $this->actingAs($this->subscriber);
    setMaintenance(false);

    $this->location->update(['label' => 'Renamed']);

    expect($this->location->fresh()->label)->toBe('Renamed');
});

test('reads still work during maintenance', function () {
    $this->actingAs($this->subscriber);
    setMaintenance(true);

    expect(Location::query()->count())->toBe(1);
});

test('staff can still write during maintenance', function () {
    $this->actingAs(User::factory()->create(['is_staff' => true]));
    setMaintenance(true);

    $this->location->update(['label' => 'Staff edit']);

    expect($this->location->fresh()->label)->toBe('Staff edit');
});

test('an impersonation session can still write during maintenance', function () {
    $this->actingAs($this->subscriber);
    setMaintenance(true);
    $this->mock(ImpersonateManager::class, function ($mock) {
        $mock->shouldReceive('isImpersonating')->andReturn(true);
        $mock->shouldReceive('getImpersonator')->andReturn(User::factory()->create(['is_staff' => true]));
    });

    $this->location->update(['label' => 'Support edit']);

    expect($this->location->fresh()->label)->toBe('Support edit');
});

test('writes with no signed-in user (queue, console, token webhooks) are never blocked', function () {
    setMaintenance(true);
    expect(auth()->check())->toBeFalse();

    $this->location->update(['label' => 'System edit']);

    expect($this->location->fresh()->label)->toBe('System edit');
});

test('the exception renders a readable 503 with the configured message', function () {
    setMaintenance(true, 'Back at 10:00 UTC.');
    $this->actingAs($this->subscriber);

    try {
        $this->location->update(['label' => 'x']);
    } catch (MaintenanceModeException $e) {
        $response = $e->render(Request::create('/dashboard/locations/1/edit'));
    }

    expect($response->getStatusCode())->toBe(503)
        ->and($response->getContent())->toContain('Back at 10:00 UTC.');
});

test('the dashboard shows the maintenance banner only while maintenance is on', function () {
    $this->actingAs($this->subscriber);

    setMaintenance(true, 'Planned deploy.');
    $this->get('/dashboard')->assertOk()->assertSee('Maintenance in progress')->assertSee('Planned deploy.');

    setMaintenance(false);
    $this->get('/dashboard')->assertOk()->assertDontSee('Maintenance in progress');
});

test('the default banner message is used when none is set', function () {
    $this->actingAs($this->subscriber);
    setMaintenance(true);

    $this->get('/dashboard')->assertSee('read-only')->assertSee('Alerts continue to be delivered as normal.');
});
