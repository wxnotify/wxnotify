<?php

namespace App\Support;

use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\ByoChannelType;

/**
 * What a subscriber is told about a delivery that did not go out: why, and what to do about it, from a fixed list
 * of our own sentences. The stored `error_detail` is only ever matched against that list, never shown, so a future
 * message that carries a provider's own words (or a URL) cannot reach the dashboard: an unrecognised one gets the
 * generic text. A failure only we can fix says so and asks for nothing.
 *
 * A BYO channel type's own failure message ("Discord did not accept the message. Check the webhook URL ...") is
 * already written for the subscriber, so it is split into its reason and its fix as it stands.
 */
final class DeliveryFailureExplanation
{
    public const GENERIC_REASON = 'This delivery did not go through.';

    public const GENERIC_FIX = 'Open the channel and check its settings. If it keeps failing, contact support.';

    public const OURS_REASON = "We're having a problem on our side.";

    public const OURS_FIX = 'There is nothing to change. If it keeps happening, contact support.';

    /**
     * Matched in order against the stored text. `ours` marks a failure the subscriber cannot fix; `channels` says
     * the fix is on the channel page.
     *
     * @var list<array{pattern: string, reason: string, fix: string, ours?: bool, channels?: bool}> every reason is our own sentence: a stored text is matched, never passed through
     */
    private const RULES = [
        ['pattern' => '/needs the Plus plan or higher/i', 'reason' => 'Your plan no longer includes this kind of channel.', 'fix' => 'Upgrade your plan, or send this alert to a different channel.', 'channels' => true],
        ['pattern' => '/has not been confirmed yet/i', 'reason' => "This channel hasn't been confirmed yet.", 'fix' => 'Open the channel and enter the confirmation code we sent.', 'channels' => true],
        ['pattern' => '/no push token|no longer registered for push/i', 'reason' => "This phone isn't registered for push notifications.", 'fix' => 'Open the WxNotify app on the phone and sign in again.'],
        ['pattern' => '/Push is not configured on this server|Firebase|Could not get a Firebase/', 'reason' => self::OURS_REASON, 'fix' => self::OURS_FIX, 'ours' => true],
        ['pattern' => '/subscription is no longer valid|missing its browser subscription/i', 'reason' => "This browser's push subscription is missing or has expired.", 'fix' => 'Turn Web Push on again from Notification Channels.', 'channels' => true],
        ['pattern' => '/Push service rejected the message/i', 'reason' => "The browser's push service rejected the message.", 'fix' => 'Turn Web Push on again from Notification Channels.', 'channels' => true],
        ['pattern' => '/push service could not be reached/i', 'reason' => "The browser's push service could not be reached.", 'fix' => 'Nothing to do now; the next alert will try again. If it keeps failing, turn Web Push on again.', 'channels' => true],
        ['pattern' => '/is missing its|has no proxy URL|extra headers are no longer valid/i', 'reason' => 'This channel is missing part of its setup.', 'fix' => 'Open the channel, check its details and save it again.', 'channels' => true],
        ['pattern' => '/server address does not match/i', 'reason' => "This channel's server address no longer matches its settings.", 'fix' => 'Open the channel, check the server address and save it again.', 'channels' => true],
        ['pattern' => '/Account SID is not valid|phone numbers are not valid/i', 'reason' => "Part of this channel's Twilio details isn't valid.", 'fix' => 'Open the channel, check the Account SID and the numbers, and save it again.', 'channels' => true],
        ['pattern' => '/^Twilio refused the Account SID or auth token\.$/', 'reason' => 'Twilio refused the Account SID or auth token.', 'fix' => 'Check them in the channel and in your Twilio console.', 'channels' => true],
        ['pattern' => '/^Twilio cannot call this number\.$/', 'reason' => 'Twilio cannot call this number.', 'fix' => 'Check the number on the channel, and that it can receive calls.', 'channels' => true],
        ['pattern' => '/^The From number is not a voice number on this Twilio account\.$/', 'reason' => "The From number isn't a voice number on your Twilio account.", 'fix' => 'Use a voice-capable number from your Twilio account as the From number.', 'channels' => true],
        ['pattern' => '/^This Twilio trial account can only call numbers verified in Twilio\.$/', 'reason' => 'Your Twilio trial account can only call numbers verified in Twilio.', 'fix' => 'Verify the number in Twilio, or upgrade the Twilio account.', 'channels' => true],
        ['pattern' => "/^Twilio's geographic permissions on this account do not allow calling this number\.$/", 'reason' => "Your Twilio account's geographic permissions do not allow calling this number.", 'fix' => "Enable that country in Twilio's geographic permissions.", 'channels' => true],
        ['pattern' => '/^Twilio is rate limiting this account; the call was not retried\.$/', 'reason' => 'Twilio is rate limiting your account, so the call was not placed.', 'fix' => 'Nothing to change; the next alert will try again.'],
        ['pattern' => '/^Twilio had an error \(HTTP 5\d\d\); the call was not retried\.$/', 'reason' => 'Twilio had a problem, so the call was not placed.', 'fix' => 'Nothing to change; the next alert will try again.'],
        ['pattern' => '/^Twilio refused the call \(HTTP \d{3}\)\.$/', 'reason' => 'Twilio refused the call.', 'fix' => "Check the channel's Twilio details and your Twilio account.", 'channels' => true],
        ['pattern' => '/^Twilio did not answer in time; a call may still have been placed\.$/', 'reason' => 'Twilio did not answer in time; the call may still have gone through.', 'fix' => 'Check your phone. If calls keep failing, check your Twilio account.', 'channels' => true],
        ['pattern' => '/Only accounts hosted by Bluesky|hosted on another server/i', 'reason' => "This Bluesky account isn't hosted by Bluesky.", 'fix' => 'WxNotify can only post to Bluesky-hosted accounts. Remove this channel and add one.', 'channels' => true],
        ['pattern' => '/could not verify the Bluesky account/i', 'reason' => "We couldn't verify the Bluesky account right now.", 'fix' => 'Nothing to change; the next alert will try again.'],
        ['pattern' => '/find a Bluesky account with that handle/i', 'reason' => "That Bluesky handle wasn't found.", 'fix' => 'Open the channel and check the handle.', 'channels' => true],
        ['pattern' => '/now belongs to a different Bluesky account/i', 'reason' => 'This handle now belongs to a different Bluesky account.', 'fix' => 'Remove this channel and add it again.', 'channels' => true],
        ['pattern' => '/ntfy server could not be reached/i', 'reason' => 'The ntfy server could not be reached.', 'fix' => 'Nothing to change now; the next alert will try again.'],
        ['pattern' => '/ntfy server is not allowed/i', 'reason' => "This channel's ntfy server isn't allowed.", 'fix' => 'Remove this channel and add it again with a different server.', 'channels' => true],
        ['pattern' => '/is not valid|are not valid/i', 'reason' => "Part of this channel's setup isn't valid.", 'fix' => 'Open the channel, check its details and save it again.', 'channels' => true],
        ['pattern' => '/endpoint returned HTTP 5\d\d/i', 'reason' => 'Your endpoint had a problem and did not accept the message.', 'fix' => 'Nothing to change in WxNotify. Check that your server is working; the next alert will try again.', 'channels' => true],
        ['pattern' => '/endpoint returned HTTP \d{3}/i', 'reason' => 'Your endpoint refused the message.', 'fix' => 'Check the webhook address, any credentials or headers, and that the endpoint accepts these requests.', 'channels' => true],
        ['pattern' => '/could not be (reached|found|resolved)/i', 'reason' => "We couldn't reach the destination.", 'fix' => 'Check the address for typos and that the server is online and reachable from the internet.', 'channels' => true],
        ['pattern' => '/endpoint did not accept the message/i', 'reason' => "Your endpoint didn't accept the message; it may be redirecting.", 'fix' => "Use the endpoint's final address. WxNotify does not follow redirects.", 'channels' => true],
        ['pattern' => '/private or reserved network|private network address|must start with https|must not contain a username|query or #fragment|port is not allowed|full domain name|full https:\/\/ address/i', 'reason' => "That address isn't allowed.", 'fix' => 'Use a public https:// address with no username, password or extra parts.', 'channels' => true],
        ['pattern' => '/Apprise reported non-delivery/i', 'reason' => "The service didn't accept the message.", 'fix' => "Check the channel's credentials and that the account is still active.", 'channels' => true],
        ['pattern' => '/turned off on this server|not configured on this server|Unknown channel type|mail server could not send|could not be prepared or sent|cannot send (text )?messages|cannot place voice calls/i', 'reason' => self::OURS_REASON, 'fix' => self::OURS_FIX, 'ours' => true],
    ];

    /** @var array<string, array{reason: string, fix: string}>|null */
    private static ?array $byoMessages = null;

    /**
     * Null for a delivery that went out (or has no status to explain).
     *
     * @return array{reason: string, fix: ?string, ours: bool, channels: bool}|null
     */
    public static function for(string $status, ?string $errorDetail): ?array
    {
        return match ($status) {
            'suppressed' => [
                'reason' => 'Held back during your quiet hours.',
                'fix' => 'It is not sent later. Change the quiet hours on the alert if you want alerts to go out then.',
                'ours' => false,
                'channels' => false,
            ],
            'skipped' => self::skipped(trim((string) $errorDetail)),
            'failure' => self::failure(trim((string) $errorDetail)),
            default => null,
        };
    }

    /**
     * @return array{reason: string, fix: ?string, ours: bool, channels: bool}
     */
    private static function skipped(string $detail): array
    {
        return match (true) {
            str_contains($detail, 'deactivated') => [
                'reason' => 'The alert was turned off before this delivery went out.',
                'fix' => 'Turn the alert back on from My Alerts if you still want it.',
                'ours' => false,
                'channels' => false,
            ],
            str_contains($detail, 'no longer exists') => [
                'reason' => 'The bulletin this alert was about was no longer available, so nothing was sent.',
                'fix' => 'There is nothing to change; the next alert will go out as usual.',
                'ours' => false,
                'channels' => false,
            ],
            default => [
                'reason' => 'This alert was skipped, so nothing was sent.',
                'fix' => 'There is nothing to change. If it keeps happening, contact support.',
                'ours' => false,
                'channels' => false,
            ],
        };
    }

    /**
     * @return array{reason: string, fix: ?string, ours: bool, channels: bool}
     */
    private static function failure(string $detail): array
    {
        $byo = self::byoMessages()[$detail] ?? null;

        if ($byo !== null) {
            return ['reason' => $byo['reason'], 'fix' => $byo['fix'], 'ours' => false, 'channels' => true];
        }

        foreach (self::RULES as $rule) {
            if (preg_match($rule['pattern'], $detail) === 1) {
                return [
                    'reason' => $rule['reason'],
                    'fix' => $rule['fix'],
                    'ours' => $rule['ours'] ?? false,
                    'channels' => $rule['channels'] ?? false,
                ];
            }
        }

        return ['reason' => self::GENERIC_REASON, 'fix' => self::GENERIC_FIX, 'ours' => false, 'channels' => true];
    }

    /**
     * Every BYO type's own failure message, split into its first sentence (why) and the rest (what to check).
     *
     * @return array<string, array{reason: string, fix: string}>
     */
    private static function byoMessages(): array
    {
        if (self::$byoMessages !== null) {
            return self::$byoMessages;
        }

        $messages = [];

        foreach (app(ChannelTypeRegistry::class)->all() as $type) {
            if (! $type instanceof ByoChannelType) {
                continue;
            }

            $text = $type->failureMessage();
            [$reason, $fix] = array_pad(preg_split('/(?<=\.)\s+/', $text, 2) ?: [$text], 2, '');

            $messages[$text] = ['reason' => $reason, 'fix' => $fix !== '' ? $fix : self::GENERIC_FIX];
        }

        return self::$byoMessages = $messages;
    }

    /**
     * Forgets the memoised BYO messages (a test that changes the registry).
     */
    public static function flush(): void
    {
        self::$byoMessages = null;
    }
}
