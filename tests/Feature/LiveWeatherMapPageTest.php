<?php

use App\Filament\Dashboard\Pages\LiveWeather\Map;
use App\Models\Location;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
});

test('a signed-in subscriber can open the Map page', function () {
    $this->actingAs(User::factory()->create());

    livewire(Map::class)->assertSuccessful();

    expect(Map::getSlug())->toBe('live-weather/map')
        ->and(Map::getUrl())->toEndWith('/live-weather/map');
});

test('the page sits in the Live Weather group as Map', function () {
    $this->actingAs(User::factory()->create());

    $group = collect(Filament::getCurrentPanel()->getNavigation())
        ->first(fn ($group) => $group->getLabel() === 'Live Weather');

    expect($group)->not->toBeNull()
        ->and(Map::getNavigationLabel())->toBe('Map')
        ->and(Map::getNavigationGroup())->toBe('Live Weather')
        ->and(Map::getNavigationSort())->toBe(1)
        ->and($group->getItems()->map->getLabel()->all())->toContain('Map');
});

test('a guest is redirected to the panel login', function () {
    $this->get(Map::getUrl())->assertRedirect(Filament::getCurrentPanel()->getLoginUrl());
});

test('the map opens on the whole United States when the viewer has no located place', function () {
    $user = User::factory()->create();
    Location::factory()->for($user)->create(['lat' => null, 'lng' => null]);
    $this->actingAs($user);

    livewire(Map::class)
        ->assertSeeHtml('data-center="39.83,-98.58"')
        ->assertSeeHtml('data-zoom="4"')
        ->assertSeeHtml('data-config=');
});

test('the map centres on the first located saved place', function () {
    $user = User::factory()->create();
    Location::factory()->for($user)->create(['lat' => null, 'lng' => null]);
    Location::factory()->for($user)->create(['lat' => 35.5, 'lng' => -97.25]);
    Location::factory()->for($user)->create(['lat' => 41.0, 'lng' => -90.0]);
    $this->actingAs($user);

    livewire(Map::class)
        ->assertSeeHtml('data-center="35.5,-97.25"')
        ->assertSeeHtml('data-zoom="8"');
});

test('another subscriber\'s place never centres the map', function () {
    Location::factory()->create(['lat' => 35.5, 'lng' => -97.25]);
    $this->actingAs(User::factory()->create());

    livewire(Map::class)->assertSeeHtml('data-center="39.83,-98.58"');
});
