<?php

use App\Models\User;
use App\Support\Filament\RecoverableAppAuthentication;
use App\Support\Filament\RecoverablePasskeyAuthentication;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Happenv\FilamentPasskeys\Testing\TestsPasskeyAuthentication;
use Illuminate\Support\Facades\Hash;
use Laravel\Passkeys\Passkey;
use Livewire\Features\SupportTesting\Testable;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    Testable::mixin(new TestsPasskeyAuthentication);

    $this->user = User::factory()->create(['password' => Hash::make('secret-password')]);
});

function addPasskeyTo(User $user): Passkey
{
    return Passkey::forceCreate([
        'user_id' => $user->id,
        'name' => 'MacBook',
        'credential_id' => fake()->unique()->sha256(),
        'credential' => ['test' => true],
    ]);
}

function startSignIn(User $user): Testable
{
    return livewire(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'secret-password'])
        ->call('authenticate');
}

test('a subscriber with a passkey is asked for it after the password', function () {
    addPasskeyTo($this->user);

    startSignIn($this->user)->assertPasskeyChallengeOffered();

    $this->assertGuest();
});

test('a subscriber without a passkey or app signs straight in', function () {
    startSignIn($this->user)->assertMultiFactorChallengeNotRequired();

    $this->assertAuthenticatedAs($this->user);
});

test('a recovery code signs a passkey subscriber in and is used up', function () {
    addPasskeyTo($this->user);
    $codes = RecoverablePasskeyAuthentication::make()->makeRecoveryCodes($this->user);

    startSignIn($this->user)
        ->set('data.multiFactor.passkey.useRecoveryCode', true)
        ->set('data.multiFactor.passkey.recoveryCode', $codes[0])
        ->call('authenticate')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($this->user);
    expect($this->user->refresh()->getAppAuthenticationRecoveryCodes())->toHaveCount(count($codes) - 1);

    auth()->logout();
    startSignIn($this->user)
        ->set('data.multiFactor.passkey.useRecoveryCode', true)
        ->set('data.multiFactor.passkey.recoveryCode', $codes[0])
        ->call('authenticate')
        ->assertHasErrors();

    $this->assertGuest();
});

test('a wrong recovery code does not sign in', function () {
    addPasskeyTo($this->user);
    RecoverablePasskeyAuthentication::make()->makeRecoveryCodes($this->user);

    startSignIn($this->user)
        ->set('data.multiFactor.passkey.useRecoveryCode', true)
        ->set('data.multiFactor.passkey.recoveryCode', 'not-a-real-code')
        ->call('authenticate')
        ->assertHasErrors();

    $this->assertGuest();
});

test('a passkey subscriber with no recovery codes cannot get in with a guessed code', function () {
    addPasskeyTo($this->user);

    startSignIn($this->user)
        ->set('data.multiFactor.passkey.useRecoveryCode', true)
        ->set('data.multiFactor.passkey.recoveryCode', 'anything')
        ->call('authenticate')
        ->assertHasErrors();

    $this->assertGuest();
});

test('new recovery codes are stored hashed and replace the old set', function () {
    $provider = RecoverablePasskeyAuthentication::make();

    $first = $provider->makeRecoveryCodes($this->user);
    $second = $provider->makeRecoveryCodes($this->user);

    $stored = $this->user->refresh()->getAppAuthenticationRecoveryCodes();

    expect($second)->toHaveCount(8)
        ->and($stored)->toHaveCount(8)
        ->and($stored)->not->toContain($second[0])
        ->and(Hash::check($second[0], $stored[0]))->toBeTrue()
        ->and(Hash::check($first[0], $stored[0]))->toBeFalse();
});

test('turning the app off keeps the recovery codes while a passkey remains', function () {
    addPasskeyTo($this->user);
    $app = RecoverableAppAuthentication::make();
    $app->saveRecoveryCodes($this->user, ['a-code']);

    $app->saveRecoveryCodes($this->user, null);

    expect($this->user->refresh()->getAppAuthenticationRecoveryCodes())->toHaveCount(1);
});

test('turning the app off deletes the recovery codes when no passkey remains', function () {
    $app = RecoverableAppAuthentication::make();
    $app->saveRecoveryCodes($this->user, ['a-code']);

    $app->saveRecoveryCodes($this->user, null);

    expect($this->user->refresh()->getAppAuthenticationRecoveryCodes())->toBeNull();
});
