<?php

namespace App\Actions\Channels;

use App\Exceptions\ChannelCodeThrottled;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\MessagingChannelType;
use App\Support\ChannelTypes\Contracts\PhoneChannelType;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class DispatchChannelConfirmationCode
{
    /**
     * A phone channel's code is a text (or call) with WxNotify's name on it to a number the
     * subscriber typed, so codes are limited per subscriber and number, and per subscriber across
     * numbers, for every entry point (wizard, Resend, the API). Other channel types are not limited
     * here, except the chat types below; the API's own app-channel-send limiter still covers them there.
     */
    public const CODES_PER_NUMBER_PER_HOUR = 5;

    public const CODES_PER_USER_PER_HOUR = 10;

    /**
     * A chat type's code is a message with WxNotify's name on it, posted to a host or room the
     * subscriber chose, so it is limited too: per subscriber and destination, and per subscriber
     * across destinations. These buckets are separate from the phone ones (a phone type that is
     * also a messaging type, WhatsApp, uses the phone buckets).
     */
    public const CHAT_CODES_PER_DESTINATION_PER_HOUR = 5;

    public const CHAT_CODES_PER_USER_PER_HOUR = 10;

    public static function userLimitKey(int $userId): string
    {
        return "phone-code-user:{$userId}";
    }

    /**
     * Keyed by a hash of the E.164 number, so the number itself never sits in the cache.
     */
    public static function numberLimitKey(int $userId, string $number): string
    {
        return "phone-code-number:{$userId}:".hash('sha256', $number);
    }

    public static function chatUserLimitKey(int $userId): string
    {
        return "chat-code-user:{$userId}";
    }

    /**
     * Keyed by a hash of the type's destination key, which can hold a webhook token.
     */
    public static function chatDestinationLimitKey(int $userId, string $destinationKey): string
    {
        return "chat-code-destination:{$userId}:".hash('sha256', $destinationKey);
    }

    /**
     * Sends the code through ChannelDispatcher: Apprise for most channel
     * types, Laravel Mail for Email. Returns false both when the message
     * wasn't delivered and, for Apprise-backed channels, when the Apprise API
     * itself is unreachable. The two are kept
     * distinguishable only via the log: AppriseApiClient::notify() isn't
     * changed to swallow ConnectionException itself, since
     * DeliverWeatherAlert/SendActivityAlert share it and rely on that
     * exception surfacing so the queue's own retry-with-backoff handles a
     * transient outage — this synchronous, in-wizard call has no such
     * retry mechanism, so it's the one that needs to catch it.
     */
    /**
     * @throws ChannelCodeThrottled when a phone or chat channel's limits are used up: nothing is sent, and
     *                              the stored code and the wrong-code counter are left as they were
     */
    public function __invoke(NotificationChannel $channel): bool
    {
        $this->spendAllowance($channel);

        $code = (string) random_int(100000, 999999);

        $channel->forceFill([
            'confirmation_code' => $code,
            'confirmation_code_expires_at' => now()->addMinutes(15),
        ])->save();

        Cache::forget(VerifyChannelConfirmationCode::attemptsKey($channel));

        try {
            return app(ChannelDispatcher::class)->send(
                $channel,
                "Your WxNotify confirmation code is: {$code}\n\nEnter this code in the dashboard to finish connecting this channel. It expires in 15 minutes.",
                'Confirm your WxNotify channel',
                ['type' => 'confirmation', 'code' => $code],
            )->success;
        } catch (ConnectionException $e) {
            logger()->error('Could not reach the Apprise API to dispatch a channel confirmation code', [
                'notification_channel_id' => $channel->id,
                'exception' => $e::class,
            ]);

            return false;
        }
    }

    /**
     * Count this send against the phone or chat limits, or refuse it when one is used up. A send
     * that goes out but fails at the provider still counts: it was an attempt to reach the
     * destination. Other channel types are not limited.
     *
     * @throws ChannelCodeThrottled
     */
    private function spendAllowance(NotificationChannel $channel): void
    {
        $type = app(ChannelTypeRegistry::class)->find($channel->channel_type);

        if ($type instanceof PhoneChannelType) {
            $number = (string) ($channel->config['to'] ?? '');
            self::refuseWhenThrottled($channel->user_id, $number);
            $limits = self::limitsFor($channel->user_id, $number);
        } elseif ($type instanceof MessagingChannelType) {
            $destinationKey = $type->destinationKey($channel->config ?? []);
            self::refuseWhenDestinationThrottled($channel->user_id, $destinationKey);
            $limits = self::chatLimitsFor($channel->user_id, $destinationKey);
        } else {
            return;
        }

        foreach (array_keys($limits) as $key) {
            RateLimiter::hit($key, 3600);
        }
    }

    /**
     * Refuse when the number or the subscriber has no codes left this hour, without spending one:
     * BeginChannelConnection asks before it stores a new phone channel.
     *
     * @throws ChannelCodeThrottled
     */
    public static function refuseWhenThrottled(int $userId, string $number): void
    {
        self::refuseWhenAnyUsedUp(self::limitsFor($userId, $number));
    }

    /**
     * Refuse when the chat destination or the subscriber has no chat codes left this hour, without
     * spending one: BeginChannelConnection asks before it stores a new messaging channel.
     *
     * @throws ChannelCodeThrottled
     */
    public static function refuseWhenDestinationThrottled(int $userId, string $destinationKey): void
    {
        self::refuseWhenAnyUsedUp(self::chatLimitsFor($userId, $destinationKey));
    }

    /**
     * @param  array<string, int>  $limits  each limiter key with its codes per hour
     *
     * @throws ChannelCodeThrottled
     */
    private static function refuseWhenAnyUsedUp(array $limits): void
    {
        foreach ($limits as $key => $maxAttempts) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                throw new ChannelCodeThrottled(RateLimiter::availableIn($key));
            }
        }
    }

    /**
     * @return array<string, int> each limiter key with its codes per hour
     */
    private static function chatLimitsFor(int $userId, string $destinationKey): array
    {
        return [
            self::chatDestinationLimitKey($userId, $destinationKey) => self::CHAT_CODES_PER_DESTINATION_PER_HOUR,
            self::chatUserLimitKey($userId) => self::CHAT_CODES_PER_USER_PER_HOUR,
        ];
    }

    /**
     * @return array<string, int> each limiter key with its codes per hour
     */
    private static function limitsFor(int $userId, string $number): array
    {
        return [
            self::numberLimitKey($userId, PhoneNumber::normalizeInternational($number) ?? '') => self::CODES_PER_NUMBER_PER_HOUR,
            self::userLimitKey($userId) => self::CODES_PER_USER_PER_HOUR,
        ];
    }
}
