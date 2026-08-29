<?php

use App\Enums\Tier;
use App\Filament\Dashboard\Pages\Auth\Register;
use App\Models\User;
use Filament\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

use function Pest\Livewire\livewire;

function fakeSuccessfulTurnstile(): void
{
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => true]),
    ]);
}

test('it validates the form data', function (array $data, array $errors) {
    fakeSuccessfulTurnstile();

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Subscriber',
            'email' => 'jane@example.com',
            'password' => 'Sup3r$ecret!',
            'passwordConfirmation' => 'Sup3r$ecret!',
            'turnstile_token' => 'valid-token',
            ...$data,
        ])
        ->call('register')
        ->assertHasFormErrors($errors)
        ->assertNotNotified()
        ->assertNoRedirect();
})->with([
    '`name` is required' => [['name' => null], ['name' => 'required']],
    '`email` is required' => [['email' => null], ['email' => 'required']],
    '`email` is a valid email address' => [['email' => Str::random()], ['email' => 'email']],
    '`password` requires a lowercase letter' => [['password' => 'SUPER123!', 'passwordConfirmation' => 'SUPER123!'], ['password']],
    '`password` requires an uppercase letter' => [['password' => 'super123!', 'passwordConfirmation' => 'super123!'], ['password']],
    '`password` requires a number' => [['password' => 'Superword!', 'passwordConfirmation' => 'Superword!'], ['password']],
    '`password` requires a special character' => [['password' => 'Super1234', 'passwordConfirmation' => 'Super1234'], ['password']],
    '`password` requires at least 8 characters' => [['password' => 'Sup3r$1', 'passwordConfirmation' => 'Sup3r$1'], ['password']],
    '`passwordConfirmation` must match `password`' => [['passwordConfirmation' => 'SomethingElse1!'], ['password' => 'same']],
]);

test('an empty turnstile token fails validation without calling Cloudflare', function () {
    Http::fake();

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Subscriber',
            'email' => 'jane@example.com',
            'password' => 'Sup3r$ecret!',
            'passwordConfirmation' => 'Sup3r$ecret!',
            'turnstile_token' => '',
        ])
        ->call('register')
        ->assertHasFormErrors(['turnstile_token' => 'required'])
        ->assertNotNotified();

    Http::assertNothingSent();
});

test('a turnstile token Cloudflare rejects fails validation', function () {
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => false]),
    ]);

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Subscriber',
            'email' => 'jane@example.com',
            'password' => 'Sup3r$ecret!',
            'passwordConfirmation' => 'Sup3r$ecret!',
            'turnstile_token' => 'bad-token',
        ])
        ->call('register')
        ->assertHasFormErrors(['turnstile_token'])
        ->assertNotNotified();
});

test('a successful registration creates a Free-tier, unverified user', function () {
    fakeSuccessfulTurnstile();

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Subscriber',
            'email' => 'jane@example.com',
            'password' => 'Sup3r$ecret!',
            'passwordConfirmation' => 'Sup3r$ecret!',
            'turnstile_token' => 'valid-token',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'jane@example.com')->sole();

    expect($user->tier())->toBe(Tier::Free)
        ->and($user->tier_override)->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeFalse();
});

test('a successful registration actually dispatches the verification email', function () {
    Notification::fake();
    fakeSuccessfulTurnstile();

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Subscriber',
            'email' => 'jane@example.com',
            'password' => 'Sup3r$ecret!',
            'passwordConfirmation' => 'Sup3r$ecret!',
            'turnstile_token' => 'valid-token',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'jane@example.com')->sole();

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('an unverified registrant cannot reach dashboard resources', function () {
    fakeSuccessfulTurnstile();

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Subscriber',
            'email' => 'jane@example.com',
            'password' => 'Sup3r$ecret!',
            'passwordConfirmation' => 'Sup3r$ecret!',
            'turnstile_token' => 'valid-token',
        ])
        ->call('register');

    $user = User::where('email', 'jane@example.com')->sole();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect(route('filament.dashboard.auth.email-verification.prompt'));
});
