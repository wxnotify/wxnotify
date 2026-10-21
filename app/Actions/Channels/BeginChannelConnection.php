<?php

namespace App\Actions\Channels;

use App\Exceptions\ChannelCodeNotSent;
use App\Exceptions\ChannelCodeThrottled;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelTypes\Contracts\ChannelType;
use App\Support\ChannelTypes\Contracts\PhoneChannelType;
use App\Support\PhoneConsent;
use App\Support\PhoneNumber;
use Illuminate\Support\Arr;
use InvalidArgumentException;

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
     * @param  NotificationChannel|null  $pending  the unverified phone channel an earlier submit of the same
     *                                             wizard run created: refreshed, not refused as a duplicate,
     *                                             when it is the subscriber's own, of this type and for the
     *                                             same number
     *
     * @throws InvalidArgumentException when the type cannot build a destination from the data, or the
     *                                  subscriber already has a channel of this phone type for the number
     * @throws ChannelCodeNotSent
     * @throws ChannelCodeThrottled when the phone number's code limit is used up (nothing was sent)
     */
    public function __invoke(User $user, ChannelType $type, array $data, ?string $label = null, ?NotificationChannel $pending = null): NotificationChannel
    {
        $destination = $type->buildDestination($data);
        $existing = $this->samePendingPhoneChannel($user, $type, $destination, $pending);

        if ($existing === null) {
            $this->refuseDuplicateNumber($user, $type, $destination);
            $existing = $this->existingWebPushChannel($user, $type, $destination);
        }

        $channel = $this->storePendingChannel($user, $type, $destination, $existing, $label);

        if ($existing === null && $type instanceof PhoneChannelType) {
            $this->recordPhoneConsent($channel, $type);
        }

        try {
            $sent = app(DispatchChannelConfirmationCode::class)($channel);
        } catch (ChannelCodeThrottled $e) {
            $sent = $e;
        }

        if ($sent !== true) {
            if ($existing === null) {
                $channel->delete();
            }

            throw $sent instanceof ChannelCodeThrottled ? $sent : new ChannelCodeNotSent;
        }

        return $channel;
    }

    /**
     * One channel per phone type and number: connecting the same type to the same number again is
     * refused rather than refreshed, which would silently unverify a working channel. Another phone
     * type on the same number is allowed. `config` is encrypted, so the numbers are only comparable
     * after decryption, and both sides are normalised so `+1…` and ten digits are one number.
     *
     * @param  array<string, mixed>  $destination
     *
     * @throws InvalidArgumentException
     */
    private function refuseDuplicateNumber(User $user, ChannelType $type, array $destination): void
    {
        if (! $type instanceof PhoneChannelType) {
            return;
        }

        $to = PhoneNumber::normalize((string) ($destination['config']['to'] ?? ''));

        $duplicate = $to !== null && NotificationChannel::query()
            ->where('user_id', $user->id)
            ->where('channel_type', $type->slug())
            ->get()
            ->contains(fn (NotificationChannel $channel): bool => PhoneNumber::normalize((string) ($channel->config['to'] ?? '')) === $to);

        if ($duplicate) {
            throw new InvalidArgumentException("You already have a {$type->label()} channel for that number.");
        }
    }

    /**
     * The given pending channel when stepping back and submitting the same number again: still
     * unverified, the subscriber's own, of this phone type and for this number. Anything else (a
     * channel id the client made up, another number) is ignored, so the usual duplicate check applies.
     *
     * @param  array<string, mixed>  $destination
     */
    private function samePendingPhoneChannel(User $user, ChannelType $type, array $destination, ?NotificationChannel $pending): ?NotificationChannel
    {
        if (! $type instanceof PhoneChannelType || $pending === null) {
            return null;
        }

        $to = PhoneNumber::normalize((string) ($destination['config']['to'] ?? ''));

        $isSame = $pending->user_id === $user->id
            && $pending->channel_type === $type->slug()
            && ! $pending->isVerified()
            && $to !== null
            && PhoneNumber::normalize((string) ($pending->config['to'] ?? '')) === $to;

        return $isSame ? $pending : null;
    }

    /**
     * The consent given on the form, recorded before the first text or call goes to the number.
     * Only the type and the consent text's version: never the number.
     */
    private function recordPhoneConsent(NotificationChannel $channel, PhoneChannelType $type): void
    {
        activity()
            ->performedOn($channel)
            ->event('phone_consent')
            ->withProperties(['channel_type' => $type->slug(), 'version' => PhoneConsent::VERSION])
            ->log('Agreed to automated weather alerts to this number');
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
