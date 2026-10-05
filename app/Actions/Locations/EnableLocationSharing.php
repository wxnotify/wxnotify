<?php

namespace App\Actions\Locations;

use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Exceptions\LocationLimitReached;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Turns location sharing on for one phone: finds or creates its mobile Location and mints the
 * narrow beacon token the native beacon uses. Order: the Location already linked to this app
 * token; else this user's unlinked app Location with the same device name (so signing in again
 * on the same phone does not duplicate it); else a new one, which must fit the tier's location
 * limit. Any earlier beacon token of this phone is revoked, so a phone has exactly one. The
 * request has no session user, so writes pin the subscriber as the activity causer.
 */
class EnableLocationSharing
{
    /**
     * @return array{location: Location, beacon_token: string}
     *
     * @throws LocationLimitReached
     */
    public function __invoke(User $user, PersonalAccessToken $appToken): array
    {
        return app(CauserResolver::class)->withCauser($user, fn (): array => DB::transaction(function () use ($user, $appToken): array {
            PersonalAccessToken::query()->where('parent_token_id', $appToken->id)->delete();

            $location = $this->linked($user, $appToken) ?? $this->relinked($user, $appToken) ?? $this->created($user, $appToken);

            $beacon = $user->createToken(
                'Location beacon - '.$appToken->name,
                ['location'],
                now()->addDays((int) config('wxnotify.app_auth.token_days')),
            );
            $beacon->accessToken->forceFill(['parent_token_id' => $appToken->id])->save();

            return ['location' => $location, 'beacon_token' => $beacon->plainTextToken];
        }));
    }

    private function linked(User $user, PersonalAccessToken $appToken): ?Location
    {
        return Location::query()->where('user_id', $user->id)->where('app_token_id', $appToken->id)->first();
    }

    private function relinked(User $user, PersonalAccessToken $appToken): ?Location
    {
        $location = Location::query()
            ->where('user_id', $user->id)
            ->where('type', LocationType::Mobile->value)
            ->where('mobile_source', MobileSource::App->value)
            ->whereNull('app_token_id')
            ->where('label', Str::limit($appToken->name, 80, ''))
            ->orderBy('id')
            ->first();

        $location?->forceFill(['app_token_id' => $appToken->id])->save();

        return $location;
    }

    private function created(User $user, PersonalAccessToken $appToken): Location
    {
        if (! $user->canCreateLocation()) {
            throw new LocationLimitReached;
        }

        $location = new Location([
            'user_id' => $user->id,
            'label' => Str::limit($appToken->name, 80, ''),
            'type' => LocationType::Mobile,
            'mobile_source' => MobileSource::App,
            'mobile_update_interval_minutes' => 5,
        ]);
        $location->forceFill(['app_token_id' => $appToken->id])->save();

        return $location;
    }
}
