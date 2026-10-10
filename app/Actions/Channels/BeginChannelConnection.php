<?php

namespace App\Actions\Channels;

use App\Exceptions\ChannelCodeNotSent;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Illuminate\Support\Arr;

/**
 * The one way a channel is created and sent its first confirmation code, for the dashboard wizard
 * and the mobile API. Stores the channel unverified, sends the code and, when it cannot be sent,
 * removes a channel it just created (nothing else could retry it, and a retry would add a duplicate
 * row every time). A channel that already existed is only ever refreshed, never deleted.
 */
class BeginChannelConnection
{
    /**
     * @param  array<string, mixed>  $data  the setup fields (and anything else the form held)
     *
     * @throws \InvalidArgumentException when the type cannot build a destination from the data
     * @throws ChannelCodeNotSent
     */
    public function __invoke(User $user, ChannelType $type, array $data, ?string $label = null): NotificationChannel
    {
        $destination = $type->buildDestination($data);
        $existing = $this->existingWebPushChannel($user, $type, $destination);
        $channel = $this->storePendingChannel($user, $type, $destination, $existing, $label);

        if (! app(DispatchChannelConfirmationCode::class)($channel)) {
            if ($existing === null) {
                $channel->delete();
            }

            throw new ChannelCodeNotSent;
        }

        return $channel;
    }

    /**
     * The subscriber's existing Web Push channel for this exact browser endpoint, if any.
     *
     * The subscribe component deliberately reuses the browser's live PushSubscription, so a
     * subscriber who runs the wizard twice in one browser would otherwise end up with two channels
     * pointing at one endpoint and receive every alert twice. `config` is encrypted, so the endpoint
     * is only comparable after decryption.
     *
     * @param  array<string, mixed>  $destination
     */
    private function existingWebPushChannel(User $user, ChannelType $type, array $destination): ?NotificationChannel
    {
        $endpoint = $destination['config']['endpoint'] ?? null;

        if ($type->slug() !== 'webpush' || ! is_string($endpoint)) {
            return null;
        }

        return NotificationChannel::query()
            ->where('user_id', $user->id)
            ->where('channel_type', 'webpush')
            ->get()
            ->first(fn (NotificationChannel $channel): bool => ($channel->config['endpoint'] ?? null) === $endpoint);
    }

    /**
     * Refresh the given channel with the newly built destination, or create one when there is
     * nothing to refresh. A refreshed channel goes back to unverified: its keys just changed.
     *
     * @param  array<string, mixed>  $destination
     */
    private function storePendingChannel(User $user, ChannelType $type, array $destination, ?NotificationChannel $existing, ?string $label): NotificationChannel
    {
        $attributes = [
            'label' => $label ?? $destination['label'] ?? $type->label(),
            ...Arr::only($destination, ['apprise_url', 'config']),
        ];

        if ($existing !== null) {
            $existing->forceFill([...$attributes, 'verified_at' => null])->save();

            return $existing;
        }

        return NotificationChannel::create([
            'user_id' => $user->id,
            'channel_type' => $type->slug(),
            ...$attributes,
        ]);
    }
}
