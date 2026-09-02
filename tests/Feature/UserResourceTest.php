<?php

use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\RelationManagers\LocationsRelationManager;
use App\Models\Location;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
});

test('staff can set a tier override and reason on a subscriber', function () {
    $subscriber = User::factory()->create();

    livewire(EditUser::class, ['record' => $subscriber->getRouteKey()])
        ->fillForm([
            'tier_override' => 'plus',
            'tier_override_reason' => 'Beta tester gift',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($subscriber->fresh()->tier_override->value)->toBe('plus')
        ->and($subscriber->fresh()->tier_override_reason)->toBe('Beta tester gift');
});

test('setting a tier override without a reason fails validation', function () {
    $subscriber = User::factory()->create();

    livewire(EditUser::class, ['record' => $subscriber->getRouteKey()])
        ->fillForm(['tier_override' => 'plus', 'tier_override_reason' => null])
        ->call('save')
        ->assertHasFormErrors(['tier_override_reason']);
});

test('staff can change a subscriber\'s password', function () {
    $subscriber = User::factory()->create(['password' => Hash::make('old-password')]);

    livewire(EditUser::class, ['record' => $subscriber->getRouteKey()])
        ->fillForm([
            'password' => 'a-new-strong-password',
            'passwordConfirmation' => 'a-new-strong-password',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('a-new-strong-password', $subscriber->fresh()->password))->toBeTrue();
});

test('a mismatched password confirmation fails validation', function () {
    $subscriber = User::factory()->create(['password' => Hash::make('old-password')]);

    livewire(EditUser::class, ['record' => $subscriber->getRouteKey()])
        ->fillForm([
            'password' => 'a-new-strong-password',
            'passwordConfirmation' => 'does-not-match',
        ])
        ->call('save')
        ->assertHasFormErrors(['password']);

    expect(Hash::check('old-password', $subscriber->fresh()->password))->toBeTrue();
});

test('leaving the password fields blank does not change the existing password', function () {
    $subscriber = User::factory()->create(['password' => Hash::make('old-password')]);

    livewire(EditUser::class, ['record' => $subscriber->getRouteKey()])
        ->fillForm(['tier_override' => 'plus', 'tier_override_reason' => 'testing'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check('old-password', $subscriber->fresh()->password))->toBeTrue();
});

test('the locations relation manager shows only the target subscriber\'s own locations', function () {
    $subscriber = User::factory()->create();
    $mine = Location::factory()->for($subscriber)->create();
    $someoneElses = Location::factory()->for(User::factory())->create();

    livewire(LocationsRelationManager::class, [
        'ownerRecord' => $subscriber,
        'pageClass' => EditUser::class,
    ])
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$someoneElses])
        ->assertActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');
});

test('a staff user cannot be impersonated', function () {
    $otherStaff = User::factory()->create(['is_staff' => true]);

    expect($otherStaff->canBeImpersonated())->toBeFalse();
});

test('a subscriber can be impersonated', function () {
    $subscriber = User::factory()->create();

    expect($subscriber->canBeImpersonated())->toBeTrue();
});

test('staff can delete a subscriber', function () {
    $subscriber = User::factory()->create();

    livewire(ListUsers::class)
        ->callTableAction('delete', $subscriber);

    expect(User::find($subscriber->id))->toBeNull();
});

test('staff cannot delete their own account', function () {
    livewire(ListUsers::class)
        ->assertTableActionHidden('delete', $this->staff);

    expect(User::find($this->staff->id))->not->toBeNull();
});
