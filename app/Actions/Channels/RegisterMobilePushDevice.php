<?php

namespace App\Actions\Channels;

use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Creates or refreshes the channel for one signed-in phone (one per app token). A request has no
 * session user and the CauserResolver override ignores causedBy(), so each write pins its causer.
 * The FCM token is a secret: it lives only in the encrypted `config`, plus a one-way hash used to
 * find stale holders of the same token.
 */
class RegisterMobilePushDevice
{
    public function __invoke(User $user, PersonalAccessToken $accessToken, string $fcmToken, string $platform): NotificationChannel
    {
        $hash = hash('sha256', $fcmToken);
        $resolver = app(CauserResolver::class);

        // The same phone under another account (it was handed on) or an older sign-in (a reinstall)
        // must stop receiving through its old channel.
        NotificationChannel::query()
            ->where('channel_type', 'mobile_push')
            ->where('device_token_hash', $hash)
            ->where(fn ($query) => $query->whereNull('personal_access_token_id')
                ->orWhere('personal_access_token_id', '!=', $accessToken->id))
            ->with('user')
            ->get()
            ->each(fn (NotificationChannel $stale) => $resolver->withCauser($stale->user, fn () => $stale->delete()));

        $channel = null;

        $resolver->withCauser($user, function () use (&$channel, $user, $accessToken, $fcmToken, $platform, $hash): void {
            $save = fn (): NotificationChannel => tap(
                NotificationChannel::query()->firstOrNew(['personal_access_token_id' => $accessToken->id]),
                fn (NotificationChannel $device) => $device->forceFill([
                    'user_id' => $user->id,
                    'channel_type' => 'mobile_push',
                    // A label the subscriber renamed on the dashboard survives a token refresh.
                    'label' => $device->exists ? $device->label : Str::limit($accessToken->name, 80, ''),
                    'apprise_url' => null,
                    'config' => ['fcm_token' => $fcmToken, 'platform' => $platform],
                    'device_token_hash' => $hash,
                    // The phone proved itself by enrolling under an authenticated app token.
                    'verified_at' => $device->verified_at ?? now(),
                ])->save(),
            );

            try {
                $channel = $save();
            } catch (UniqueConstraintViolationException) {
                // Two registrations for one sign-in raced; the loser updates the winner's row.
                $channel = $save();
            }
        });

        return $channel;
    }
}
