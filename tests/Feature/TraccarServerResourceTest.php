<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Resources\TraccarServers\Pages\CreateTraccarServer;
use App\Filament\Dashboard\Resources\TraccarServers\Pages\EditTraccarServer;
use App\Filament\Dashboard\Resources\TraccarServers\Pages\ListTraccarServers;
use App\Filament\Dashboard\Resources\TraccarServers\TraccarServerResource;
use App\Models\TraccarServer;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Livewire\livewire;

test('a Plus subscriber can create a Traccar server that polls successfully', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);

    Http::fake(['traccar.example.com/api/positions' => Http::response([])]);

    livewire(CreateTraccarServer::class)
        ->fillForm(['label' => 'Home Server', 'base_url' => 'https://traccar.example.com', 'api_token' => 'tok123'])
        ->call('create')
        ->assertNotified()
        ->assertHasNoFormErrors();

    $server = TraccarServer::where('user_id', $user->id)->where('label', 'Home Server')->sole();
    expect($server->last_polled_at)->not->toBeNull();
    expect($server->last_poll_error)->toBeNull();
});

test('a server that fails to poll is not created', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);

    Http::fake(['bad.example.com/api/positions' => Http::response(['error' => 'Unauthorized'], 401)]);

    livewire(CreateTraccarServer::class)
        ->fillForm(['label' => 'Broken Server', 'base_url' => 'https://bad.example.com', 'api_token' => 'wrong-token'])
        ->call('create')
        ->assertNotified();

    assertDatabaseMissing(TraccarServer::class, ['user_id' => $user->id, 'label' => 'Broken Server']);
});

test('a server that cannot be reached at all is not created', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $this->actingAs($user);

    Http::fake(['unreachable.example.com/*' => fn () => throw new ConnectionException('Connection refused')]);

    livewire(CreateTraccarServer::class)
        ->fillForm(['label' => 'Unreachable Server', 'base_url' => 'https://unreachable.example.com', 'api_token' => 'tok123'])
        ->call('create')
        ->assertNotified();

    assertDatabaseMissing(TraccarServer::class, ['user_id' => $user->id, 'label' => 'Unreachable Server']);
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

    // The tier-limit check runs before the connectivity probe, so no HTTP
    // call should happen here at all -- Http::fake() with no responses
    // configured means any unexpected call fails loudly rather than
    // silently hitting the real network.
    Http::fake();

    livewire(CreateTraccarServer::class)
        ->fillForm(['label' => 'Second Server', 'base_url' => 'https://two.example.com', 'api_token' => 'tok456'])
        ->call('create')
        ->assertNotified();

    expect(TraccarServer::where('user_id', $user->id)->count())->toBe(1);
    Http::assertNothingSent();
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
