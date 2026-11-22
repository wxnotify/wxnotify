<?php

namespace App\Actions\Account;

use App\Enums\InviteCodeStatus;
use App\Enums\SignUpPolicy;
use App\Enums\SignUpRefusal;
use App\Models\InviteCode;
use App\Models\User;
use App\Settings\SignUpSettings;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The one place that decides who may create a subscriber account. Every sign-up
 * door (the Register page, Google) asks it, so the rules cannot drift apart.
 */
class SignUpGate
{
    private const SESSION_KEY = 'sign_up.pending_invite';

    /**
     * An unrecognised stored value fails closed: it can only come from a manual edit, and the owner
     * was restricting sign-ups, so falling back to Open would defeat that.
     */
    public function policy(): SignUpPolicy
    {
        return SignUpPolicy::tryFrom(app(SignUpSettings::class)->policy) ?? SignUpPolicy::Closed;
    }

    /**
     * Read-only. A null email skips the email lock (the code is checked before the address is known).
     */
    public function check(?string $code, ?string $email): SignUpDecision
    {
        return match ($this->policy()) {
            SignUpPolicy::Open => SignUpDecision::allow(),
            SignUpPolicy::Closed => SignUpDecision::refuse(SignUpRefusal::Closed),
            SignUpPolicy::InviteOnly => $this->checkInvite($this->findInvite($code), $email),
        };
    }

    /**
     * Creates the user through the closure and spends the invite in one transaction. The code row is
     * locked while it is re-checked, so two sign-ups cannot both take the last use.
     *
     * @param  Closure(): User  $createUser
     *
     * @throws SignUpRefused
     */
    public function admit(?string $code, string $email, Closure $createUser): User
    {
        return DB::transaction(function () use ($code, $email, $createUser): User {
            $policy = $this->policy();

            if ($policy === SignUpPolicy::Open) {
                return $createUser();
            }

            if ($policy === SignUpPolicy::Closed) {
                throw new SignUpRefused(SignUpRefusal::Closed);
            }

            $invite = $this->findInvite($code, lock: true);
            $decision = $this->checkInvite($invite, $email);

            if (! $decision->allowed) {
                throw new SignUpRefused($decision->refusal);
            }

            $user = $createUser();
            $user->forceFill(['invite_code_id' => $invite->id])->save();
            $invite->increment('used_count');

            return $user;
        });
    }

    public function rememberCode(string $code): void
    {
        session()->put(self::SESSION_KEY, InviteCode::normalise($code));
    }

    public function rememberedCode(): ?string
    {
        $code = session()->get(self::SESSION_KEY);

        return is_string($code) && $code !== '' ? $code : null;
    }

    public function forgetCode(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    private function findInvite(?string $code, bool $lock = false): ?InviteCode
    {
        $normalised = InviteCode::normalise($code);

        if ($normalised === '') {
            return null;
        }

        $query = InviteCode::query()->where('code', $normalised);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function checkInvite(?InviteCode $invite, ?string $email): SignUpDecision
    {
        if ($invite === null || $invite->status() !== InviteCodeStatus::Active) {
            return SignUpDecision::refuse(SignUpRefusal::InvalidCode);
        }

        if ($email !== null && $invite->email !== null && mb_strtolower($invite->email) !== mb_strtolower(trim($email))) {
            return SignUpDecision::refuse(SignUpRefusal::EmailMismatch);
        }

        return SignUpDecision::allow();
    }
}
