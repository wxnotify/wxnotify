<?php

namespace App\Actions\Account;

use App\Models\User;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Cancels a scheduled account deletion. The rules and channels were never touched, so alerts simply resume.
 */
class CancelAccountDeletion
{
    public function __invoke(User $user): void
    {
        $user->forceFill(['deletion_requested_at' => null])->save();

        app(CauserResolver::class)->withCauser($user, fn () => activity()
            ->performedOn($user)
            ->event('deletion-cancelled')
            ->log('Cancelled account deletion'));
    }
}
