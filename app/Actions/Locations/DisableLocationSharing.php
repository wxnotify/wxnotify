<?php

namespace App\Actions\Locations;

use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Turns sharing off for one phone: revokes its beacon token and unlinks its Location, which is
 * kept with its last position and its rule links. Nothing to do (and no error) when sharing
 * was never on.
 */
class DisableLocationSharing
{
    public function __invoke(User $user, PersonalAccessToken $appToken): void
    {
        app(CauserResolver::class)->withCauser($user, fn () => DB::transaction(function () use ($user, $appToken): void {
            PersonalAccessToken::query()->where('parent_token_id', $appToken->id)->delete();

            Location::query()
                ->where('user_id', $user->id)
                ->where('app_token_id', $appToken->id)
                ->get()
                ->each(fn (Location $location) => $location->forceFill(['app_token_id' => null])->save());
        }));
    }
}
