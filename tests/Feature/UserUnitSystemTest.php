<?php

use App\Enums\UnitSystem;
use App\Models\User;

test('a new user is Imperial by default', function () {
    $user = User::factory()->create();

    expect($user->fresh()->unit_system)->toBe(UnitSystem::Imperial);
});

test('the unit system is mass assignable and cast to the enum', function () {
    $user = User::factory()->create();

    $user->update(['unit_system' => 'metric']);

    expect($user->fresh()->unit_system)->toBe(UnitSystem::Metric);
});
