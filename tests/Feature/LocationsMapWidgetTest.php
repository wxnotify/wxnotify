<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Resources\Locations\Pages\ListLocations;
use App\Filament\Dashboard\Resources\Locations\Widgets\LocationsMapWidget;
use App\Models\Location;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->user = User::factory()->create(['tier_override' => Tier::Free]);
    $this->actingAs($this->user);
});

test('the widget returns only the signed-in subscriber\'s locations', function () {
    Location::factory()->for($this->user)->create(['label' => 'Mine', 'lat' => 40.0, 'lng' => -105.0]);
    Location::factory()->create(['label' => 'Theirs', 'lat' => 41.0, 'lng' => -106.0]);

    $data = livewire(LocationsMapWidget::class)->instance()->locations();

    expect(collect($data['locations'])->pluck('label')->all())->toBe(['Mine']);
});

test('with no locations the widget returns an empty payload', function () {
    expect(livewire(LocationsMapWidget::class)->instance()->locations())->toBe(['locations' => [], 'unplaced' => 0]);
});

test('every tier can view the widget', function () {
    foreach ([Tier::Free, Tier::Plus, Tier::Pro] as $tier) {
        $this->actingAs(User::factory()->create(['tier_override' => $tier]));

        expect(LocationsMapWidget::canView())->toBeTrue();
    }
});

test('the widget renders a map container that Livewire leaves alone and no external call is made', function () {
    Location::factory()->for($this->user)->mobile()->create();
    Http::fake();

    livewire(LocationsMapWidget::class)
        ->assertSeeHtml('data-locations-map')
        ->assertSeeHtml('data-locations-caption')
        ->assertSeeHtml('wire:ignore')
        ->assertSeeHtml('if (! window.wxMapHelpers)')
        ->call('locations');

    Http::assertNothingSent();
});

test('the Locations list page shows the map, with locations and with none', function () {
    livewire(ListLocations::class)->assertSeeLivewire(LocationsMapWidget::class);

    Location::factory()->for($this->user)->create();

    livewire(ListLocations::class)->assertSeeLivewire(LocationsMapWidget::class);
});

test('the widget payload never contains traccar identifiers or the owner', function () {
    Location::factory()->for($this->user)->mobile()->create(['lat' => 40.0, 'lng' => -105.0, 'mobile_position_updated_at' => now()]);

    $json = json_encode(livewire(LocationsMapWidget::class)->instance()->locations());

    expect($json)->not->toContain('traccar')->not->toContain('user_id');
});
