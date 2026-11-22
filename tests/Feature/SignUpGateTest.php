<?php

use App\Actions\Account\SignUpGate;
use App\Actions\Account\SignUpRefused;
use App\Enums\SignUpPolicy;
use App\Enums\SignUpRefusal;
use App\Models\InviteCode;
use App\Models\User;
use App\Settings\SignUpSettings;

function setSignUpPolicy(string $policy): void
{
    $settings = app(SignUpSettings::class);
    $settings->policy = $policy;
    $settings->save();
}

function createUserFor(string $email): Closure
{
    return fn (): User => User::factory()->create(['email' => $email]);
}

const INVALID_CODE_MESSAGE = "That invite code isn't valid.";

it('allows anyone when sign-ups are open and ignores a code', function () {
    $invite = InviteCode::factory()->create(['code' => 'ABCDEFGH']);
    $gate = app(SignUpGate::class);

    expect($gate->policy())->toBe(SignUpPolicy::Open)
        ->and($gate->check(null, 'a@b.test')->allowed)->toBeTrue();

    $user = $gate->admit('ABCDEFGH', 'a@b.test', createUserFor('a@b.test'));

    expect($user->invite_code_id)->toBeNull()
        ->and($invite->fresh()->used_count)->toBe(0);
});

it('refuses everyone when sign-ups are closed, even with a valid code', function () {
    setSignUpPolicy('closed');
    InviteCode::factory()->create(['code' => 'ABCDEFGH']);
    $gate = app(SignUpGate::class);

    $decision = $gate->check('ABCDEFGH', 'a@b.test');

    expect($decision->allowed)->toBeFalse()
        ->and($decision->refusal)->toBe(SignUpRefusal::Closed)
        ->and($decision->message())->toBe('Sign-ups are closed. Ask the administrator for an account.');

    expect(fn () => $gate->admit('ABCDEFGH', 'a@b.test', createUserFor('a@b.test')))
        ->toThrow(SignUpRefused::class);
    expect(User::count())->toBe(0);
});

it('refuses a missing, unknown, expired, revoked or used up code with one message', function (?string $code) {
    setSignUpPolicy('invite_only');
    InviteCode::factory()->expired()->create(['code' => 'EXPIRED1']);
    InviteCode::factory()->revoked()->create(['code' => 'REVOKED1']);
    InviteCode::factory()->usedUp()->create(['code' => 'USEDUP11']);

    $decision = app(SignUpGate::class)->check($code, 'a@b.test');

    expect($decision->allowed)->toBeFalse()
        ->and($decision->refusal)->toBe(SignUpRefusal::InvalidCode)
        ->and($decision->message())->toBe(INVALID_CODE_MESSAGE);
})->with([null, '', 'NOSUCHCODE', 'EXPIRED1', 'REVOKED1', 'USEDUP11']);

it('refuses a code that expires exactly now', function () {
    $this->freezeTime();
    setSignUpPolicy('invite_only');
    InviteCode::factory()->create(['code' => 'ABCDEFGH', 'expires_at' => now()]);

    expect(app(SignUpGate::class)->check('ABCDEFGH', null)->refusal)->toBe(SignUpRefusal::InvalidCode);
});

it('matches a valid code however it was typed', function (string $typed) {
    setSignUpPolicy('invite_only');
    InviteCode::factory()->create(['code' => 'ABCDEFGH']);

    expect(app(SignUpGate::class)->check($typed, null)->allowed)->toBeTrue();
})->with(['abcd-efgh', ' ABCD EFGH ']);

it('locks a code to its email case-insensitively', function () {
    setSignUpPolicy('invite_only');
    InviteCode::factory()->forEmail('Friend@Example.test')->create(['code' => 'ABCDEFGH']);
    $gate = app(SignUpGate::class);

    expect($gate->check('ABCDEFGH', 'FRIEND@example.TEST')->allowed)->toBeTrue()
        ->and($gate->check('ABCDEFGH', null)->allowed)->toBeTrue();

    $decision = $gate->check('ABCDEFGH', 'other@example.test');

    expect($decision->refusal)->toBe(SignUpRefusal::EmailMismatch)
        ->and($decision->message())->toBe('This invite is for a different email address.');
});

it('admits a user with a single-use code once only', function () {
    setSignUpPolicy('invite_only');
    $invite = InviteCode::factory()->create(['code' => 'ABCDEFGH']);
    $gate = app(SignUpGate::class);

    $user = $gate->admit('abcd-efgh', 'a@b.test', createUserFor('a@b.test'));

    expect($user->fresh()->invite_code_id)->toBe($invite->id)
        ->and($invite->fresh()->used_count)->toBe(1);

    try {
        $gate->admit('ABCDEFGH', 'c@d.test', createUserFor('c@d.test'));
        $this->fail('The second use should be refused.');
    } catch (SignUpRefused $e) {
        expect($e->refusal)->toBe(SignUpRefusal::InvalidCode)
            ->and($e->getMessage())->toBe(INVALID_CODE_MESSAGE);
    }

    expect(User::count())->toBe(1);
});

it('refuses admission when the email does not match the lock', function () {
    setSignUpPolicy('invite_only');
    $invite = InviteCode::factory()->forEmail('friend@example.test')->create(['code' => 'ABCDEFGH']);

    try {
        app(SignUpGate::class)->admit('ABCDEFGH', 'other@example.test', createUserFor('other@example.test'));
        $this->fail('The mismatch should be refused.');
    } catch (SignUpRefused $e) {
        expect($e->refusal)->toBe(SignUpRefusal::EmailMismatch);
    }

    expect(User::count())->toBe(0)
        ->and($invite->fresh()->used_count)->toBe(0);
});

it('stops a shared code at its limit and never stops an unlimited one', function () {
    setSignUpPolicy('invite_only');
    $shared = InviteCode::factory()->shared(3)->create(['code' => 'SHARED11']);
    $unlimited = InviteCode::factory()->create(['code' => 'FOREVER1', 'max_uses' => null]);
    $gate = app(SignUpGate::class);

    foreach (range(1, 3) as $i) {
        $gate->admit('SHARED11', "s{$i}@b.test", createUserFor("s{$i}@b.test"));
    }

    expect(fn () => $gate->admit('SHARED11', 's4@b.test', createUserFor('s4@b.test')))
        ->toThrow(SignUpRefused::class);
    expect($shared->fresh()->used_count)->toBe(3);

    foreach (range(1, 5) as $i) {
        $gate->admit('FOREVER1', "u{$i}@b.test", createUserFor("u{$i}@b.test"));
    }

    expect($unlimited->fresh()->used_count)->toBe(5)
        ->and(User::count())->toBe(8);
});

it('rolls back the created user when the closure fails after creating it', function () {
    setSignUpPolicy('invite_only');
    $invite = InviteCode::factory()->create(['code' => 'ABCDEFGH']);

    expect(fn () => app(SignUpGate::class)->admit('ABCDEFGH', 'a@b.test', function (): User {
        User::factory()->create(['email' => 'a@b.test']);

        throw new LogicException('boom');
    }))->toThrow(LogicException::class, 'boom');

    expect($invite->fresh()->used_count)->toBe(0)
        ->and(User::count())->toBe(0);
});

it('rolls back the created user when recording the invite on it fails', function () {
    setSignUpPolicy('invite_only');
    $invite = InviteCode::factory()->create(['code' => 'ABCDEFGH']);

    User::saving(function (User $user): void {
        if ($user->invite_code_id !== null) {
            throw new LogicException('boom');
        }
    });

    expect(fn () => app(SignUpGate::class)->admit('ABCDEFGH', 'a@b.test', createUserFor('a@b.test')))
        ->toThrow(LogicException::class, 'boom');

    expect($invite->fresh()->used_count)->toBe(0)
        ->and(User::count())->toBe(0);
});

it('remembers a pending invite as a normalised code', function () {
    $gate = app(SignUpGate::class);

    expect($gate->rememberedCode())->toBeNull();

    $gate->rememberCode('ab-cd');

    expect($gate->rememberedCode())->toBe('ABCD');

    $gate->forgetCode();

    expect($gate->rememberedCode())->toBeNull();
});
