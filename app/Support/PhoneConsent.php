<?php

namespace App\Support;

/**
 * The consent a subscriber gives when connecting a text or call channel.
 *
 * A draft pending the owner's legal review (TCPA): it is not a compliance claim, and the
 * phone channel types ship switched off until that review is done. Changing the wording
 * means a new VERSION, because each channel's config records the version agreed to.
 */
class PhoneConsent
{
    public const VERSION = '2026-10-draft';

    /**
     * The wording, with `{provider}` (the provider's name), `{messages}` ("texts" or
     * "calls") and `{stop}` (the reply-STOP clause, texts only) filled in by text().
     */
    public const TEXT = 'I own this phone number or am allowed to use it, and I agree to receive automated weather alert {messages} at it, sent through my own {provider} account. Message, data and {provider} charges may apply and are mine. I can stop them at any time by deleting this channel{stop}.';

    public const STOP_CLAUSE = ', or by replying STOP where my provider supports it';

    /**
     * The WhatsApp wording: same VERSION and draft status as TEXT. WhatsApp has no reply-STOP
     * handling, so blocking the business number is the way to stop besides deleting the channel.
     */
    public const WHATSAPP_TEXT = 'I own this phone number or am allowed to use it, and I have opted in to receive automated weather alert messages at it on WhatsApp, sent from my own WhatsApp Business account. Meta\'s per-message charges for these messages are mine. I can stop them at any time by deleting this channel or by blocking the business number in WhatsApp.';

    public static function text(string $provider, bool $voice): string
    {
        return strtr(self::TEXT, [
            '{provider}' => $provider,
            '{messages}' => $voice ? 'calls' : 'texts',
            '{stop}' => $voice ? '' : self::STOP_CLAUSE,
        ]);
    }

    public static function whatsappText(): string
    {
        return self::WHATSAPP_TEXT;
    }
}
