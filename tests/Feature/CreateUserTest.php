<?php

use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use App\Notifications\PrefixedResetPassword;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->staff = User::factory()->create(['is_staff' => true]);
    $this->actingAs($this->staff);
});

test('the list page shows a New user action', function () {
    livewire(ListUsers::class)->assertActionExists('create');
});

test('creating a user by emailing a password-reset link sends the link, verifies the email immediately, and sets an unusable password', function () {
    Notification::fake();

    livewire(CreateUser::class)
        ->fillForm([
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'password_setup' => 'send_reset_link',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(UserResource::getUrl('index'));

    $user = User::where('email', 'jamie@example.com')->sole();

    expect($user->name)->toBe('Jamie Rivera')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->is_staff)->toBeFalse()
        ->and($user->is_owner)->toBeFalse()
        ->and(Hash::check($user->password, $user->password))->toBeFalse();

    // PrefixedResetPassword, not Filament's own ResetPassword: AppServiceProvider
    // binds the container to resolve our subclass instead, so the message
    // prefix setting (see MessagePrefixTest) can reach this email too.
    Notification::assertSentTo($user, PrefixedResetPassword::class);
});

test('the password-reset link points at the dashboard panel, not admin', function () {
    Notification::fake();

    livewire(CreateUser::class)
        ->fillForm([
            'name' => 'Jamie Rivera',
            'email' => 'jamie@example.com',
            'password_setup' => 'send_reset_link',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'jamie@example.com')->sole();

    Notification::assertSentTo($user, function (PrefixedResetPassword $notification) {
        expect($notification->url)->toContain('/dashboard/')->not->toContain('/admin/');

        return true;
    });

    expect(Filament::getCurrentPanel()?->getId())->toBe('admin');
});

test('creating a user by setting the password directly requires a policy-compliant password and skips the email', function () {
    Notification::fake();

    livewire(CreateUser::class)
        ->fillForm([
            'name' => 'Riley Chen',
            'email' => 'riley@example.com',
            'password_setup' => 'set_directly',
            'password' => 'short',
            'passwordConfirmation' => 'short',
        ])
        ->call('create')
        ->assertHasFormErrors(['password']);

    expect(User::where('email', 'riley@example.com')->exists())->toBeFalse();

    livewire(CreateUser::class)
        ->fillForm([
            'name' => 'Riley Chen',
            'email' => 'riley@example.com',
            'password_setup' => 'set_directly',
            'password' => 'Correct-Horse-1',
            'passwordConfirmation' => 'Correct-Horse-1',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'riley@example.com')->sole();

    expect(Hash::check('Correct-Horse-1', $user->password))->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();

    Notification::assertNothingSent();
});

test('choosing to set the password now but leaving it blank is rejected, not silently created with no password', function () {
    livewire(CreateUser::class)
        ->fillForm([
            'name' => 'Riley Chen',
            'email' => 'riley@example.com',
            'password_setup' => 'set_directly',
        ])
        ->call('create')
        ->assertHasFormErrors(['password']);

    expect(User::where('email', 'riley@example.com')->exists())->toBeFalse();
});

test('a mismatched password confirmation is rejected', function () {
    livewire(CreateUser::class)
        ->fillForm([
            'name' => 'Riley Chen',
            'email' => 'riley@example.com',
            'password_setup' => 'set_directly',
            'password' => 'Correct-Horse-1',
            'passwordConfirmation' => 'Different-1234',
        ])
        ->call('create')
        ->assertHasFormErrors(['password']);
});

test('the email must be unique', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    livewire(CreateUser::class)
        ->fillForm([
            'name' => 'Jamie Rivera',
            'email' => 'taken@example.com',
            'password_setup' => 'send_reset_link',
        ])
        ->call('create')
        ->assertHasFormErrors(['email']);
});

test('is_owner, is_staff and tier_override can be set on create the same as on edit, gated the same way', function () {
    livewire(CreateUser::class)
        ->assertFormFieldDisabled('is_owner')
        ->fillForm([
            'name' => 'New Staffer',
            'email' => 'staffer@example.com',
            'password_setup' => 'send_reset_link',
            'is_staff' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'staffer@example.com')->sole()->is_staff)->toBeTrue();

    $this->actingAs(User::factory()->create(['is_owner' => true]));

    livewire(CreateUser::class)
        ->assertFormFieldEnabled('is_owner')
        ->fillForm([
            'name' => 'New Owner',
            'email' => 'owner@example.com',
            'password_setup' => 'send_reset_link',
            'is_owner' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'owner@example.com')->sole()->is_owner)->toBeTrue();
});

test('name and email stay disabled on the edit form', function () {
    $target = User::factory()->create();

    livewire(EditUser::class, ['record' => $target->id])
        ->assertFormFieldDisabled('name')
        ->assertFormFieldDisabled('email');
});
