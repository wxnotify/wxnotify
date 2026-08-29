<?php

use App\Enums\Tier;
use App\Models\User;

test('a user with no tier override defaults to free', function () {
    $user = User::factory()->create();

    expect($user->tier())->toBe(Tier::Free);
});

test('an admin-set tier override takes precedence', function () {
    $user = User::factory()->create(['tier_override' => Tier::Pro]);

    expect($user->tier())->toBe(Tier::Pro);
});
