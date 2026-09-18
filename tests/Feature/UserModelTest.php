<?php

use App\Models\User;

test('is_test_account casts to boolean', function () {
    $user = User::factory()->create(['is_test_account' => true]);

    expect($user->fresh()->is_test_account)->toBeTrue();
});
