<?php

use App\Models\User;

test('a non-staff user cannot access the admin panel', function () {
    $user = User::factory()->create(['is_staff' => false]);

    $this->actingAs($user)
        ->get('/admin')
        ->assertForbidden();
});

test('a staff user can access the admin panel', function () {
    $user = User::factory()->create(['is_staff' => true]);

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk();
});

test('any authenticated user can access the dashboard panel', function () {
    $user = User::factory()->create(['is_staff' => false]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk();
});

test('logging out of the admin panel redirects to the dashboard login page', function () {
    $user = User::factory()->create(['is_staff' => true]);

    $this->actingAs($user)
        ->post('/admin/logout')
        ->assertRedirect('/dashboard/login');
});

test('logging out of the dashboard panel redirects to its own login page', function () {
    $user = User::factory()->create(['is_staff' => false]);

    $this->actingAs($user)
        ->post('/dashboard/logout')
        ->assertRedirect('/dashboard/login');
});
