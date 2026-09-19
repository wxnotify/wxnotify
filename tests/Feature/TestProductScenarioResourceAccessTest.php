<?php

use App\Filament\Admin\Resources\TestProductScenarios\Pages\ListTestProductScenarios;
use App\Filament\Admin\Resources\TestProductScenarios\TestProductScenarioResource;
use App\Models\TestProductScenario;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

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

test('a staff user can see the New scenario action on the list page', function () {
    app()->instance('env', 'testing');
    $staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($staff);
    Filament::setCurrentPanel('admin');

    livewire(ListTestProductScenarios::class)
        ->assertActionExists('create');
});

test('the generator pages return 403 over HTTP in production', function () {
    app()->instance('env', 'production');
    $this->actingAs(User::factory()->create(['is_staff' => true]));
    $scenario = TestProductScenario::factory()->create();

    $this->get('/admin/test-product-scenarios')->assertForbidden();
    $this->get('/admin/test-product-scenarios/create')->assertForbidden();
    $this->get("/admin/test-product-scenarios/{$scenario->id}/edit")->assertForbidden();
});
