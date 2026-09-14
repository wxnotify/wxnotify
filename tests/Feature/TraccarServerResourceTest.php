<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Resources\TraccarServers\Pages\CreateTraccarServer;
use App\Filament\Dashboard\Resources\TraccarServers\Pages\EditTraccarServer;
use App\Filament\Dashboard\Resources\TraccarServers\Pages\ListTraccarServers;
use App\Filament\Dashboard\Resources\TraccarServers\TraccarServerResource;
use App\Models\TraccarServer;
use App\Models\User;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Livewire\livewire;

test('a Plus subscriber can create a Traccar server', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);

    livewire(CreateTraccarServer::class)
        ->fillForm(['label' => 'Home Server', 'base_url' => 'https://traccar.example.com', 'api_token' => 'tok123'])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors();

    assertDatabaseHas(TraccarServer::class, ['user_id' => $user->id, 'label' => 'Home Server']);
});

test('a Free subscriber cannot access the resource at all', function () {
    $user = User::factory()->create(['tier_override' => Tier::Free]);
    $this->actingAs($user);

    expect(TraccarServerResource::canAccess())->toBeFalse();
});

test('a Plus subscriber is blocked from creating a second server', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    TraccarServer::factory()->for($user)->create();
    $this->actingAs($user);

    livewire(CreateTraccarServer::class)
        ->fillForm(['label' => 'Second Server', 'base_url' => 'https://two.example.com', 'api_token' => 'tok456'])
        ->call('create')
        ->assertNotified();

    expect(TraccarServer::where('user_id', $user->id)->count())->toBe(1);
});

test('the list only shows the current user\'s own servers', function () {
    $user = User::factory()->create(['tier_override' => Tier::Pro]);
    $other = User::factory()->create(['tier_override' => Tier::Pro]);
    $mine = TraccarServer::factory()->for($user)->create();
    TraccarServer::factory()->for($other)->create();
    $this->actingAs($user);

    livewire(ListTraccarServers::class)
        ->assertCanSeeTableRecords([$mine]);
});

test('an existing server can be edited', function () {
    $user = User::factory()->create(['tier_override' => Tier::Pro]);
    $server = TraccarServer::factory()->for($user)->create();
    $this->actingAs($user);

    livewire(EditTraccarServer::class, ['record' => $server->id])
        ->fillForm(['label' => 'Renamed'])
        ->call('save')
        ->assertNotified()
        ->assertHasNoFormErrors();

    assertDatabaseHas(TraccarServer::class, ['id' => $server->id, 'label' => 'Renamed']);
});
