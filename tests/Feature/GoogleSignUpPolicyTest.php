<?php

use App\Actions\Account\SignUpGate;
use App\Models\InviteCode;
use App\Models\User;
use App\Settings\SignUpSettings;
use DutchCodingCompany\FilamentSocialite\FilamentSocialitePlugin;
use Filament\Facades\Filament;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;

function googlePlugin(): FilamentSocialitePlugin
{
    return Filament::getPanel('dashboard')->getPlugin('filament-socialite');
}

function googleUserFor(string $email, ?string $name = 'Gail Google'): SocialiteUserContract
{
    return new class($email, $name) implements SocialiteUserContract
    {
        public function __construct(private string $email, private ?string $name) {}

        public function getId(): string
        {
            return 'google-123';
        }

        public function getNickname(): ?string
        {
            return null;
        }

        public function getName(): ?string
        {
            return $this->name;
        }

        public function getEmail(): ?string
        {
            return $this->email;
        }

        public function getAvatar(): ?string
        {
            return null;
        }
    };
}

function googleRegistrationAllowed(string $email, ?User $existing = null): bool
{
    $closure = googlePlugin()->getRegistration();

    return $closure('google', googleUserFor($email), $existing);
}

function googleCreateUser(string $email): User
{
    $callback = googlePlugin()->getCreateUserUsing();

    return $callback('google', googleUserFor($email), googlePlugin());
}

function setGooglePolicy(string $policy): void
{
    $settings = app(SignUpSettings::class);
    $settings->policy = $policy;
    $settings->save();
}

it('lets a new Google user register and creates a verified user when sign-ups are open', function () {
    $invite = InviteCode::factory()->create(['code' => 'ABCDEFGH']);

    expect(googleRegistrationAllowed('new@example.test'))->toBeTrue();

    $user = googleCreateUser('new@example.test');

    expect($user->email)->toBe('new@example.test')
        ->and($user->name)->toBe('Gail Google')
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->invite_code_id)->toBeNull()
        ->and($invite->fresh()->used_count)->toBe(0);
});

it('refuses a new Google user when sign-ups are closed but still lets an existing user in', function () {
    setGooglePolicy('closed');
    $existing = User::factory()->create(['email' => 'old@example.test']);

    expect(googleRegistrationAllowed('new@example.test'))->toBeFalse()
        ->and(googleRegistrationAllowed('old@example.test', $existing))->toBeTrue();
});

it('needs a remembered, usable invite code under invite only', function () {
    setGooglePolicy('invite_only');
    $gate = app(SignUpGate::class);

    expect(googleRegistrationAllowed('new@example.test'))->toBeFalse();

    InviteCode::factory()->create(['code' => 'GOODCODE']);
    $gate->rememberCode('GOODCODE');
    expect(googleRegistrationAllowed('new@example.test'))->toBeTrue();

    InviteCode::factory()->usedUp()->create(['code' => 'USEDUP11']);
    $gate->rememberCode('USEDUP11');
    expect(googleRegistrationAllowed('new@example.test'))->toBeFalse();

    InviteCode::factory()->revoked()->create(['code' => 'REVOKED1']);
    $gate->rememberCode('REVOKED1');
    expect(googleRegistrationAllowed('new@example.test'))->toBeFalse();
});

it('refuses a Google email that differs from the email the invite is locked to', function () {
    setGooglePolicy('invite_only');
    InviteCode::factory()->forEmail('invited@example.test')->create(['code' => 'LOCKED11']);
    app(SignUpGate::class)->rememberCode('LOCKED11');

    expect(googleRegistrationAllowed('other@example.test'))->toBeFalse()
        ->and(googleRegistrationAllowed('invited@example.test'))->toBeTrue();
});

it('lets an existing user in under invite only without a code', function () {
    setGooglePolicy('invite_only');
    $existing = User::factory()->create(['email' => 'old@example.test']);

    expect(googleRegistrationAllowed('old@example.test', $existing))->toBeTrue();
});

it('spends the remembered invite code when creating the Google user and forgets it', function () {
    setGooglePolicy('invite_only');
    $invite = InviteCode::factory()->create(['code' => 'GOODCODE']);
    $gate = app(SignUpGate::class);
    $gate->rememberCode('GOODCODE');

    $user = googleCreateUser('new@example.test');

    expect($user->invite_code_id)->toBe($invite->id)
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($invite->fresh()->used_count)->toBe(1)
        ->and($gate->rememberedCode())->toBeNull();
});
