<?php

use App\Models\User;
use App\Support\Filament\RecoverableAppAuthentication;
use Filament\Auth\Pages\EditProfile;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = User::factory()->create(['is_staff' => true, 'password' => Hash::make('secret-password')]);
});

test('an admin can open their profile page and sees the two-factor section', function () {
    $this->actingAs($this->admin);

    $this->get(Filament::getPanel('admin')->getProfileUrl())->assertOk();

    livewire(EditProfile::class)->assertSee('Authenticator app')->assertSee('Passkey');
});

test('an admin with an authenticator app is asked for a code after the password', function () {
    $this->admin->saveAppAuthenticationSecret(RecoverableAppAuthentication::make()->generateSecret());

    livewire(Login::class)
        ->fillForm(['email' => $this->admin->email, 'password' => 'secret-password'])
        ->call('authenticate')
        ->assertSet('userUndertakingMultiFactorAuthentication', fn ($value) => $value !== null);

    $this->assertGuest();
});
