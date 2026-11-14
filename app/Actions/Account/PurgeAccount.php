<?php

namespace App\Actions\Account;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Permanently deletes an account. The user row's foreign keys cascade almost everything; this also removes what
 * does not cascade (Sanctum tokens, web sessions, the audit-log rows naming the person) and leaves one anonymous
 * entry carrying only the user id.
 */
class PurgeAccount
{
    public function __invoke(User $user): void
    {
        $id = $user->getKey();
        $morph = $user->getMorphClass();

        DB::transaction(function () use ($user, $id, $morph): void {
            $user->tokens()->delete();
            UserSession::query()->ownedBy($user)->delete();

            Activity::query()
                ->where(fn ($query) => $query->where('causer_type', $morph)->where('causer_id', $id))
                ->orWhere(fn ($query) => $query->where('subject_type', $morph)->where('subject_id', $id))
                ->delete();

            $user->delete();
        });

        activity()->event('account-deleted')->withProperties(['user_id' => $id])->log('Account deleted');
    }
}
