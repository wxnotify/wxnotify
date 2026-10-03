<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Resources\Locations\Pages\EditLocation;
use App\Filament\Dashboard\Resources\Locations\Pages\ListLocations;
use App\Filament\Dashboard\Resources\TraccarServers\Pages\EditTraccarServer;
use App\Filament\Dashboard\Resources\TraccarServers\Pages\ListTraccarServers;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\TraccarServer;
use App\Models\User;
use App\Support\LocationDeletionGuard;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->user = User::factory()->create(['tier_override' => Tier::Pro]);
    $this->actingAs($this->user);
});

/** The bodies of the notifications Filament has queued for the current user, as one string. */
function sentNotificationBodies(): string
{
    $notifications = new Notifications;
    $notifications->mount();

    return $notifications->notifications->map(fn (Notification $notification): string => (string) $notification->getBody())->implode(' ');
}

/** A Daily Summary alert that uses the location through its single location_id. */
function scheduledAlertUsing(Location $location, string $name = 'Morning summary'): AlertRule
{
    return AlertRule::factory()->dailySummary()->for($location->user)->for($location)->create(['name' => $name]);
}

/** A location-mode Weather Alerts rule that uses the location through the locations list. */
function listedAlertUsing(Location $location, string $name = 'Storm warnings'): AlertRule
{
    return AlertRule::factory()->locations($location)->for($location->user)->create([
        'name' => $name, 'category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => null,
    ]);
}

test('alerts are found through the single location_id', function () {
    $location = Location::factory()->for($this->user)->create();
    $alert = scheduledAlertUsing($location);

    expect(LocationDeletionGuard::alertsUsing($location)->pluck('id')->all())->toBe([$alert->id]);
});

test('alerts are found through the locations list', function () {
    $location = Location::factory()->for($this->user)->create();
    $alert = listedAlertUsing($location);

    expect(LocationDeletionGuard::alertsUsing($location)->pluck('id')->all())->toBe([$alert->id]);
});

test('several alerts are all returned, and an alert using the location both ways appears once', function () {
    $location = Location::factory()->for($this->user)->create();
    $scheduled = scheduledAlertUsing($location);
    $listed = listedAlertUsing($location);
    $both = AlertRule::factory()->locations($location)->for($this->user)->create([
        'name' => 'Both ways', 'category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => $location->id,
    ]);

    expect(LocationDeletionGuard::alertsUsing($location)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$scheduled->id, $listed->id, $both->id])->sort()->values()->all());
});

test('a location no alert uses, and alerts using other locations, do not block', function () {
    $mine = Location::factory()->for($this->user)->create();
    $other = Location::factory()->for($this->user)->create();
    scheduledAlertUsing($other);
    listedAlertUsing($other);

    expect(LocationDeletionGuard::alertsUsing($mine))->toBeEmpty();
});

test('a Traccar server is blocked by alerts using any of its locations', function () {
    $server = TraccarServer::factory()->for($this->user)->create();
    $used = Location::factory()->mobile()->for($this->user)->create(['traccar_server_id' => $server->id]);
    Location::factory()->mobile()->for($this->user)->create(['traccar_server_id' => $server->id]);
    $alert = scheduledAlertUsing($used);

    expect(LocationDeletionGuard::alertsUsingServer($server)->pluck('id')->all())->toBe([$alert->id]);
    expect(LocationDeletionGuard::alertsUsingServer(TraccarServer::factory()->for($this->user)->create()))->toBeEmpty();
});

test('the summary names up to five alerts and counts the rest', function () {
    $location = Location::factory()->for($this->user)->create();
    foreach (range(1, 7) as $i) {
        listedAlertUsing($location, "Alert {$i}");
    }

    $summary = LocationDeletionGuard::summary(LocationDeletionGuard::alertsUsing($location));

    expect($summary)->toContain('Alert 1')->toContain('Alert 5')->not->toContain('Alert 6')->toContain('and 2 more');
});

test('a location used by an alert cannot be deleted from the Locations table', function () {
    $location = Location::factory()->for($this->user)->create(['label' => 'Home']);
    scheduledAlertUsing($location);

    livewire(ListLocations::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($location))
        ->assertNotified("Can't delete Home");

    expect(Location::find($location->id))->not->toBeNull();
});

test('an unused location is still deleted from the Locations table', function () {
    $location = Location::factory()->for($this->user)->create();

    livewire(ListLocations::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($location))
        ->assertNotified();

    expect(Location::find($location->id))->toBeNull();
});

test('a location used by an alert cannot be deleted from its edit page', function () {
    $location = Location::factory()->for($this->user)->create(['label' => 'Home']);
    listedAlertUsing($location);

    livewire(EditLocation::class, ['record' => $location->id])
        ->callAction(DeleteAction::class)
        ->assertNotified("Can't delete Home")
        ->assertNoRedirect();

    expect(Location::find($location->id))->not->toBeNull();
});

test('an unused location is still deleted from its edit page', function () {
    $location = Location::factory()->for($this->user)->create();

    livewire(EditLocation::class, ['record' => $location->id])
        ->callAction(DeleteAction::class)
        ->assertRedirect();

    expect(Location::find($location->id))->toBeNull();
});

test('bulk delete removes unused locations, keeps the ones alerts use and says which', function () {
    $used = Location::factory()->for($this->user)->create(['label' => 'Home']);
    $free1 = Location::factory()->for($this->user)->create();
    $free2 = Location::factory()->for($this->user)->create();
    scheduledAlertUsing($used, 'Morning summary');

    livewire(ListLocations::class)
        ->selectTableRecords([$used->id, $free1->id, $free2->id])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertNotified();

    expect(Location::find($used->id))->not->toBeNull()
        ->and(Location::find($free1->id))->toBeNull()
        ->and(Location::find($free2->id))->toBeNull();
});

test('bulk delete reports the blocked location and its alerts, with the label escaped', function () {
    $used = Location::factory()->for($this->user)->create(['label' => '<b>Home</b>']);
    scheduledAlertUsing($used, 'Morning summary');

    livewire(ListLocations::class)
        ->selectTableRecords([$used->id])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

    // Reading the queued notifications consumes them, so do it once, without assertNotified().
    $body = sentNotificationBodies();

    expect(Location::find($used->id))->not->toBeNull()
        ->and($body)->toContain('Morning summary')
        ->toContain('&lt;b&gt;Home&lt;/b&gt;')
        ->not->toContain('<b>Home</b>');
});

test('a Traccar server whose location an alert uses cannot be deleted from its edit page', function () {
    $server = TraccarServer::factory()->for($this->user)->create(['label' => 'Fleet']);
    $location = Location::factory()->mobile()->for($this->user)->create(['traccar_server_id' => $server->id]);
    scheduledAlertUsing($location);

    livewire(EditTraccarServer::class, ['record' => $server->id])
        ->callAction(DeleteAction::class)
        ->assertNotified("Can't delete Fleet")
        ->assertNoRedirect();

    expect(TraccarServer::find($server->id))->not->toBeNull()
        ->and(Location::find($location->id))->not->toBeNull();
});

test('a Traccar server with no alert-used locations is deleted as before', function () {
    $server = TraccarServer::factory()->for($this->user)->create();
    Location::factory()->mobile()->for($this->user)->create(['traccar_server_id' => $server->id]);

    livewire(EditTraccarServer::class, ['record' => $server->id])
        ->callAction(DeleteAction::class)
        ->assertRedirect();

    expect(TraccarServer::find($server->id))->toBeNull();
});

test('bulk deleting Traccar servers keeps the ones whose locations alerts use', function () {
    $blocked = TraccarServer::factory()->for($this->user)->create(['label' => 'Fleet']);
    $free = TraccarServer::factory()->for($this->user)->create(['label' => 'Spare']);
    scheduledAlertUsing(Location::factory()->mobile()->for($this->user)->create(['traccar_server_id' => $blocked->id]));

    livewire(ListTraccarServers::class)
        ->selectTableRecords([$blocked->id, $free->id])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertNotified();

    expect(TraccarServer::find($blocked->id))->not->toBeNull()
        ->and(TraccarServer::find($free->id))->toBeNull();
});

test('another subscriber\'s alerts never block my location', function () {
    $location = Location::factory()->for($this->user)->create();
    $stranger = User::factory()->create();
    AlertRule::factory()->dailySummary()->for($stranger)->create(['location_id' => Location::factory()->for($stranger)->create()->id]);

    expect(LocationDeletionGuard::alertsUsing($location))->toBeEmpty();
});
