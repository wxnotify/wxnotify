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
     * @var list<array{pattern: string, reason: string, fix: string, ours?: bool, channels?: bool}>
     */
    private const RULES = [
        ['pattern' => '/needs the Plus plan or higher/i', 'reason' => 'Your plan no longer includes this kind of channel.', 'fix' => 'Upgrade your plan, or send this alert to a different channel.', 'channels' => true],
        ['pattern' => '/has not been confirmed yet/i', 'reason' => "This channel hasn't been confirmed yet.", 'fix' => 'Open the channel and enter the confirmation code we sent.', 'channels' => true],
        ['pattern' => '/no push token/i', 'reason' => "This phone isn't registered for push notifications.", 'fix' => 'Open the WxNotify app on the phone and sign in again.'],
        ['pattern' => '/subscription is no longer valid/i', 'reason' => "This browser's push subscription has expired.", 'fix' => 'Turn Web Push on again from Notification Channels.', 'channels' => true],
        ['pattern' => '/Push service rejected the message/i', 'reason' => "The browser's push service rejected the message.", 'fix' => 'Turn Web Push on again from Notification Channels.', 'channels' => true],
        ['pattern' => '/push service could not be reached/i', 'reason' => "The browser's push service could not be reached.", 'fix' => 'Nothing to do now; the next alert will try again. If it keeps failing, turn Web Push on again.', 'channels' => true],
        ['pattern' => '/is missing its|has no proxy URL|extra headers are no longer valid/i', 'reason' => 'This channel is missing part of its setup.', 'fix' => 'Open the channel, check its details and save it again.', 'channels' => true],
        ['pattern' => '/Twilio/', 'reason' => '', 'fix' => "Check the channel's Twilio details and your Twilio account.", 'channels' => true],
        ['pattern' => '/is not valid|are not valid/i', 'reason' => "Part of this channel's setup isn't valid.", 'fix' => 'Open the channel, check its details and save it again.', 'channels' => true],
        ['pattern' => '/endpoint returned HTTP 5\d\d/i', 'reason' => 'Your endpoint had a problem and did not accept the message.', 'fix' => 'Nothing to change in WxNotify. Check that your server is working; the next alert will try again.', 'channels' => true],
        ['pattern' => '/endpoint returned HTTP \d{3}/i', 'reason' => 'Your endpoint refused the message.', 'fix' => 'Check the webhook address, any credentials or headers, and that the endpoint accepts these requests.', 'channels' => true],
        ['pattern' => '/could not be (reached|found|resolved)/i', 'reason' => "We couldn't reach the destination.", 'fix' => 'Check the address for typos and that the server is online and reachable from the internet.', 'channels' => true],
        ['pattern' => '/endpoint did not accept the message/i', 'reason' => "Your endpoint didn't accept the message; it may be redirecting.", 'fix' => "Use the endpoint's final address. WxNotify does not follow redirects.", 'channels' => true],
        ['pattern' => '/private or reserved network|private network address|must start with https|must not contain a username|query or #fragment|port is not allowed|full domain name|full https:\/\/ address/i', 'reason' => "That address isn't allowed.", 'fix' => 'Use a public https:// address with no username, password or extra parts.', 'channels' => true],
        ['pattern' => '/ntfy server is not allowed/i', 'reason' => "This channel's ntfy server isn't allowed.", 'fix' => 'Remove this channel and add it again with a different server.', 'channels' => true],
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
            'skipped' => [
                'reason' => 'The alert was turned off before this delivery went out.',
                'fix' => 'Turn the alert back on from My Alerts if you still want it.',
                'ours' => false,
                'channels' => false,
            ],
            'failure' => self::failure(trim((string) $errorDetail)),
            default => null,
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
                    'reason' => $rule['reason'] !== '' ? $rule['reason'] : $detail,
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
