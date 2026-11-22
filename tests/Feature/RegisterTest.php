<?php

use App\Actions\Account\SignUpGate;
use App\Enums\SignUpRefusal;
use App\Enums\Tier;
use App\Filament\Dashboard\Pages\Auth\Register;
use App\Models\InviteCode;
use App\Models\User;
use App\Notifications\PrefixedVerifyEmail;
use App\Settings\SignUpSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

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

    // PrefixedVerifyEmail, not Filament's own VerifyEmail: AppServiceProvider
    // binds the container to resolve our subclass instead, so the message
    // prefix setting (see MessagePrefixTest) can reach this email too.
    Notification::assertSentTo($user, PrefixedVerifyEmail::class);
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

test('a self-hosted install signs up without a captcha when both turnstile keys are blank', function () {
    config([
        'wxnotify.mode' => 'selfhost',
        'turnstile.turnstile_site_key' => null,
        'turnstile.turnstile_secret_key' => null,
    ]);
    Http::fake();

    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Subscriber',
            'email' => 'jane@example.com',
            'password' => 'Sup3r$ecret!',
            'passwordConfirmation' => 'Sup3r$ecret!',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'jane@example.com')->exists())->toBeTrue();
    Http::assertNothingSent();
});

test('the hosted cloud service keeps the captcha even when both turnstile keys are blank, so a missing key fails loudly', function () {
    config([
        'wxnotify.mode' => 'cloud',
        'turnstile.turnstile_site_key' => null,
        'turnstile.turnstile_secret_key' => null,
    ]);
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
        ->assertHasFormErrors(['turnstile_token' => 'required']);

    expect(User::where('email', 'jane@example.com')->exists())->toBeFalse();
});

test('the captcha still applies when only one turnstile key is set, so a half-configured install fails loudly', function (string $configured) {
    config([
        'wxnotify.mode' => 'selfhost',
        'turnstile.turnstile_site_key' => $configured === 'site' ? 'site-key' : null,
        'turnstile.turnstile_secret_key' => $configured === 'secret' ? 'secret-key' : null,
    ]);
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
        ->assertHasFormErrors(['turnstile_token' => 'required']);

    expect(User::where('email', 'jane@example.com')->exists())->toBeFalse();
})->with(['only the site key' => ['site'], 'only the secret key' => ['secret']]);

function setRegisterPolicy(string $policy): void
{
    $settings = app(SignUpSettings::class);
    $settings->policy = $policy;
    $settings->save();
}

/**
 * @return array<string, mixed>
 */
function registrationDetails(array $overrides = []): array
{
    return [
        'name' => 'Jane Subscriber',
        'email' => 'jane@example.com',
        'password' => 'Sup3r$ecret!',
        'passwordConfirmation' => 'Sup3r$ecret!',
        'turnstile_token' => 'valid-token',
        ...$overrides,
    ];
}

test('the invite code field is hidden when sign-ups are open', function () {
    livewire(Register::class)->assertFormFieldHidden('invite_code');
});

test('a closed policy sends visitors to the login page with the closed message', function () {
    setRegisterPolicy('closed');

    livewire(Register::class)
        ->assertRedirect(Filament::getLoginUrl())
        ->assertNotified(SignUpRefusal::Closed->message());
});

test('a form mounted while open is refused once the policy turns closed', function () {
    fakeSuccessfulTurnstile();
    $page = livewire(Register::class);
    setRegisterPolicy('closed');

    $page->fillForm(registrationDetails())
        ->call('register')
        ->assertHasFormErrors(['email']);

    expect(User::where('email', 'jane@example.com')->exists())->toBeFalse();
});

test('invite only shows a required invite code field', function () {
    setRegisterPolicy('invite_only');

    livewire(Register::class)->assertFormFieldVisible('invite_code');

    fakeSuccessfulTurnstile();
    livewire(Register::class)
        ->fillForm(registrationDetails(['invite_code' => '']))
        ->call('register')
        ->assertHasFormErrors(['invite_code' => 'required']);
});

test('a valid single-use code creates the account and is spent', function () {
    setRegisterPolicy('invite_only');
    fakeSuccessfulTurnstile();
    $invite = InviteCode::factory()->create();

    livewire(Register::class)
        ->fillForm(registrationDetails(['invite_code' => $invite->code]))
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'jane@example.com')->sole();
    expect($user->invite_code_id)->toBe($invite->id)
        ->and($invite->refresh()->used_count)->toBe(1);
});

test('a code that cannot be used gives a code error and creates no user', function (string $state) {
    setRegisterPolicy('invite_only');
    fakeSuccessfulTurnstile();
    $invite = InviteCode::factory()->{$state}()->create();

    livewire(Register::class)
        ->fillForm(registrationDetails(['invite_code' => $invite->code]))
        ->call('register')
        ->assertHasFormErrors(['invite_code']);

    expect(User::where('email', 'jane@example.com')->exists())->toBeFalse();
})->with(['expired', 'revoked', 'usedUp']);

test('a code locked to another email gives the mismatch message', function () {
    setRegisterPolicy('invite_only');
    fakeSuccessfulTurnstile();
    $invite = InviteCode::factory()->forEmail('someone@example.com')->create();

    livewire(Register::class)
        ->fillForm(registrationDetails(['invite_code' => $invite->code]))
        ->call('register')
        ->assertHasFormErrors(['invite_code'])
        ->assertSee(SignUpRefusal::EmailMismatch->message());

    expect(User::where('email', 'jane@example.com')->exists())->toBeFalse();
});

test('a code in the link prefills the field and is remembered when valid', function () {
    setRegisterPolicy('invite_only');
    $invite = InviteCode::factory()->create();

    Livewire::withQueryParams(['code' => $invite->code])
        ->test(Register::class)
        ->assertFormSet(['invite_code' => $invite->code]);

    expect(app(SignUpGate::class)->rememberedCode())->toBe(InviteCode::normalise($invite->code));
});

test('an invalid code in the link is prefilled but not remembered', function () {
    setRegisterPolicy('invite_only');

    Livewire::withQueryParams(['code' => 'ZZZZ-ZZZZ'])
        ->test(Register::class)
        ->assertFormSet(['invite_code' => 'ZZZZ-ZZZZ']);

    expect(app(SignUpGate::class)->rememberedCode())->toBeNull();
});

test('the captcha still applies under invite only', function () {
    setRegisterPolicy('invite_only');
    Http::fake();
    $invite = InviteCode::factory()->create();

    livewire(Register::class)
        ->fillForm(registrationDetails(['invite_code' => $invite->code, 'turnstile_token' => '']))
        ->call('register')
        ->assertHasFormErrors(['turnstile_token' => 'required']);

    expect(User::where('email', 'jane@example.com')->exists())->toBeFalse();
});
