<?php

use App\Filament\Admin\Resources\TestProductScenarios\TestProductScenarioResource;
use App\Models\User;

test('the resource is hidden and inaccessible when APP_ENV is production', function () {
    // app()->environment() reads the container's 'env' binding (set once at
    // boot from APP_ENV), not config('app.env') -- config() alone would not
    // move it, so the container binding is overridden directly here.
    app()->instance('env', 'production');
    $staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($staff);

    expect(TestProductScenarioResource::shouldRegisterNavigation())->toBeFalse()
        ->and(TestProductScenarioResource::canAccess())->toBeFalse();
});

test('a staff user can access the resource outside production', function () {
    app()->instance('env', 'testing');
    $staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($staff);

    expect(TestProductScenarioResource::shouldRegisterNavigation())->toBeTrue()
        ->and(TestProductScenarioResource::canAccess())->toBeTrue();
});
