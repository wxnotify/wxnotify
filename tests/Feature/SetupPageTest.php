<?php

use App\Filament\Admin\Pages\Setup;
use App\Models\User;
use App\Support\Setup\ReferenceDataStatus;
use Filament\Actions\Action;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

test('a non-owner, including staff, cannot open the setup page', function () {
    $this->actingAs(User::factory()->create(['is_staff' => true, 'is_owner' => false]));

    expect(Setup::canAccess())->toBeFalse();

    livewire(Setup::class)->assertForbidden();
});

test('the owner sees a card for each set with its count and state', function () {
    $this->actingAs(User::factory()->create(['is_staff' => true, 'is_owner' => true]));

    $component = livewire(Setup::class)->assertSuccessful();

    foreach (ReferenceDataStatus::sets() as $set) {
        $component->assertSee($set['label'])
            ->assertSee(number_format($set['count']));
    }

    $component->assertSee('Not installed');
});

test('the install action installs the missing sets and says what it installed', function () {
    $this->actingAs(User::factory()->create(['is_staff' => true, 'is_owner' => true]));

    expect(ReferenceDataStatus::allInstalled())->toBeFalse();

    livewire(Setup::class)
        ->assertActionExists('installReferenceData', fn (Action $action): bool => str_contains((string) $action->getModalDescription(), 'Nothing that already exists is changed')
            && str_contains((string) $action->getModalDescription(), 'removes 7 legacy products'))
        ->callAction('installReferenceData')
        ->assertNotified('Installed: Product catalog, Did you know tips, Shapefile layers');

    expect(ReferenceDataStatus::allInstalled())->toBeTrue();
});

test('the install action is disabled once everything is installed', function () {
    $this->actingAs(User::factory()->create(['is_staff' => true, 'is_owner' => true]));

    livewire(Setup::class)->callAction('installReferenceData');

    livewire(Setup::class)
        ->assertActionDisabled('installReferenceData')
        ->assertSee('Installed');
});
