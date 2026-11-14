<?php

namespace App\Actions\Account;

use App\Mail\AccountDeletionScheduledMail;
use App\Models\User;
use App\Models\UserSession;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Schedules the deletion of a subscriber's account: signs it out everywhere (every web session and every
 * Sanctum token, phones and API keys alike), marks it, and emails the address on file how to cancel.
 * Nothing else is touched, so cancelling needs no restore step. The caller signs the current browser out.
 */
class RequestAccountDeletion
{
    public function __invoke(User $user): void
    {
        throw_unless($user->canRequestDeletion(), DomainException::class, 'Staff and owners cannot delete their account here.');

        DB::transaction(function () use ($user): void {
            $user->forceFill(['deletion_requested_at' => now()])->save();
            $user->tokens()->delete();
            UserSession::query()->ownedBy($user)->delete();
        });

        app(CauserResolver::class)->withCauser($user, fn () => activity()
            ->performedOn($user)
            ->event('deletion-requested')
            ->log('Requested account deletion'));

        Mail::to($user)->send(new AccountDeletionScheduledMail($user));
    }
}
