<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Resources\TraccarServers\Pages\ListTraccarServers;
use App\Filament\Dashboard\Resources\TraccarServers\Widgets\TraccarDevicesMapWidget;
use App\Models\TraccarServer;
use App\Models\User;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($this->user);
});

function widgetTraccarFake(string $host, string $deviceName): array
{
    return [
        "{$host}/api/devices" => Http::response([['id' => 1, 'name' => $deviceName, 'lastUpdate' => now()->toIso8601String()]]),
        "{$host}/api/positions" => Http::response([['deviceId' => 1, 'latitude' => 40.0, 'longitude' => -105.0, 'serverTime' => now()->toIso8601String()]]),
    ];
}

test('the widget returns only the signed-in subscriber\'s devices', function () {
    TraccarServer::factory()->for($this->user)->create(['label' => 'Mine', 'base_url' => 'https://mine.example.com']);
    TraccarServer::factory()->create(['label' => 'Theirs', 'base_url' => 'https://theirs.example.com']);
    Http::fake([...widgetTraccarFake('mine.example.com', 'My truck'), ...widgetTraccarFake('theirs.example.com', 'Their truck')]);

    $data = livewire(TraccarDevicesMapWidget::class)->instance()->devices();

    expect(collect($data['devices'])->pluck('name')->all())->toBe(['My truck'])
        ->and($data['has_servers'])->toBeTrue()
        ->and($data['unreachable'])->toBe([]);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'theirs.example.com'));
});

test('with no servers the widget reports it and makes no requests', function () {
    Http::fake();

    $data = livewire(TraccarDevicesMapWidget::class)->instance()->devices();

    expect($data)->toBe(['devices' => [], 'unplaced' => 0, 'unreachable' => [], 'has_servers' => false]);
    Http::assertNothingSent();
});

test('the widget payload never contains a token or a server url', function () {
    TraccarServer::factory()->for($this->user)->create(['label' => 'Mine', 'base_url' => 'https://mine.example.com', 'api_token' => 'secret-token-123']);
    Http::fake(widgetTraccarFake('mine.example.com', 'Truck'));

    $json = json_encode(livewire(TraccarDevicesMapWidget::class)->instance()->devices());

    expect($json)->not->toContain('secret-token-123')->not->toContain('mine.example.com');
});

test('the widget renders a map container that Livewire leaves alone, and loads nothing from Traccar while rendering', function () {
    TraccarServer::factory()->for($this->user)->create(['base_url' => 'https://mine.example.com']);
    Http::fake();

    livewire(TraccarDevicesMapWidget::class)
        ->assertSeeHtml('data-traccar-map')
        ->assertSeeHtml('data-traccar-caption')
        ->assertSeeHtml('wire:ignore');

    Http::assertNothingSent();
});

test('the Traccar Servers list page shows the map, with servers and with none', function () {
    livewire(ListTraccarServers::class)->assertSeeLivewire(TraccarDevicesMapWidget::class);

    TraccarServer::factory()->for($this->user)->create();

    livewire(ListTraccarServers::class)->assertSeeLivewire(TraccarDevicesMapWidget::class);
});

test('a Free-tier subscriber cannot reach the widget data method', function () {
    config(['app.debug' => false]);
    $this->actingAs(User::factory()->create(['tier_override' => Tier::Free]));
    TraccarServer::factory()->for(auth()->user())->create(['base_url' => 'https://mine.example.com']);
    Http::fake();

    livewire(TraccarDevicesMapWidget::class)->call('devices')->assertNotFound();

    Http::assertNothingSent();
});

test('a Plus subscriber gets the payload back from the renderless widget data method', function () {
    Http::fake();

    livewire(TraccarDevicesMapWidget::class)
        ->call('devices')
        ->assertReturned(['devices' => [], 'unplaced' => 0, 'unreachable' => [], 'has_servers' => false]);
});

test('the widget loads the shared map helpers and no longer defines its own', function () {
    livewire(TraccarDevicesMapWidget::class)
        ->assertSeeHtml('if (! window.wxMapHelpers)')
        ->assertDontSeeHtml('const formatCheckin')
        ->assertDontSeeHtml('const relativeTime')
        ->assertDontSeeHtml('const pinIcon');
});
