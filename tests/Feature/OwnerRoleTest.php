<?php

use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Models\User;
use Filament\Facades\Filament;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

function ownerRoleMakeOwner(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->forceFill(['is_owner' => true])->save();

    return $user;
}

test('an owner is always staff', function () {
    $owner = ownerRoleMakeOwner(['is_staff' => false]);

    expect($owner->fresh()->is_staff)->toBeTrue()
        ->and($owner->fresh()->is_owner)->toBeTrue();
});

test('is_owner defaults to false', function () {
    expect(User::factory()->create()->fresh()->is_owner)->toBeFalse();
});

test('is_owner is not mass assignable', function () {
    $user = User::factory()->create();
    $user->update(['is_owner' => true]);

    expect($user->fresh()->is_owner)->toBeFalse();
});

test('changing is_owner is written to the audit log', function () {
    $user = User::factory()->create(['is_staff' => true]);
    $user->forceFill(['is_owner' => true])->save();

    expect(Activity::where('subject_id', $user->id)->latest('id')->first()->attribute_changes->toArray())
        ->toHaveKey('attributes.is_owner');
});

test('only an owner can change the owner toggle in the admin user form', function () {
    Filament::setCurrentPanel('admin');
    $target = User::factory()->create(['is_staff' => true]);

    $this->actingAs(User::factory()->create(['is_staff' => true]));
    livewire(EditUser::class, ['record' => $target->getRouteKey()])
        ->assertFormFieldDisabled('is_owner')
        ->fillForm(['is_owner' => true])
        ->call('save');
    expect($target->fresh()->is_owner)->toBeFalse();

    $this->actingAs(ownerRoleMakeOwner());
    livewire(EditUser::class, ['record' => $target->getRouteKey()])
        ->assertFormFieldEnabled('is_owner')
        ->fillForm(['is_owner' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->is_owner)->toBeTrue();
});

test('the grant-owner command promotes a user by email and refuses unknown emails', function () {
    $user = User::factory()->create(['email' => 'boss@example.com']);

    $this->artisan('app:grant-owner', ['email' => 'boss@example.com'])->assertSuccessful();
    expect($user->fresh()->is_owner)->toBeTrue();

    $this->artisan('app:grant-owner', ['email' => 'nobody@example.com'])->assertFailed();
});
