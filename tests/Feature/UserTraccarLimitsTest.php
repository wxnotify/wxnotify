<?php

use App\Enums\Tier;
use App\Models\Location;
use App\Models\TraccarServer;
use App\Models\User;

test('Free tier cannot create any Traccar servers', function () {
    $user = User::factory()->create(['tier_override' => Tier::Free]);

    expect($user->traccarServerLimit())->toBe(0);
    expect($user->canCreateTraccarServer())->toBeFalse();
});

test('Plus tier can create up to 1 Traccar server', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);

    expect($user->canCreateTraccarServer())->toBeTrue();

    TraccarServer::factory()->for($user)->create();

    expect($user->canCreateTraccarServer())->toBeFalse();
});

test('Pro tier can create up to 3 Traccar servers', function () {
    $user = User::factory()->create(['tier_override' => Tier::Pro]);
    TraccarServer::factory()->count(3)->for($user)->create();

    expect($user->canCreateTraccarServer())->toBeFalse();
});

test('Free tier cannot add any Traccar devices', function () {
    $user = User::factory()->create(['tier_override' => Tier::Free]);

    expect($user->traccarDeviceLimitPerServer())->toBe(0);
});

test('canAddTraccarDevice returns false for a server the user does not own', function () {
    $owner = User::factory()->create(['tier_override' => Tier::Pro]);
    $otherUser = User::factory()->create(['tier_override' => Tier::Pro]);
    $server = TraccarServer::factory()->for($owner)->create();

    expect($otherUser->canAddTraccarDevice($server->id))->toBeFalse();
});

test('Plus tier can attach up to 5 devices per server', function () {
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $server = TraccarServer::factory()->for($user)->create();

    expect($user->canAddTraccarDevice($server->id))->toBeTrue();

    Location::factory()->mobile()->for($user)->create(['traccar_server_id' => $server->id]);
    Location::factory()->mobile()->for($user)->create(['traccar_server_id' => $server->id]);
    Location::factory()->mobile()->for($user)->create(['traccar_server_id' => $server->id]);
    Location::factory()->mobile()->for($user)->create(['traccar_server_id' => $server->id]);
    Location::factory()->mobile()->for($user)->create(['traccar_server_id' => $server->id]);

    expect($user->canAddTraccarDevice($server->id))->toBeFalse();
});

test('a device limit on one server does not affect a different server', function () {
    $user = User::factory()->create(['tier_override' => Tier::Pro]);
    $serverA = TraccarServer::factory()->for($user)->create();
    $serverB = TraccarServer::factory()->for($user)->create();

    Location::factory()->mobile()->for($user)->create(['traccar_server_id' => $serverA->id]);

    expect($user->canAddTraccarDevice($serverA->id))->toBeTrue();
    expect($user->canAddTraccarDevice($serverB->id))->toBeTrue();
});
