<?php

namespace App\Support\ChannelTypes\Contracts;

/**
 * A chat or messaging destination the subscriber owns (a Google Chat space, a Mattermost or
 * Rocket.Chat channel, a Matrix room, a WhatsApp number). The webhook secret, token or
 * credentials live in the encrypted `config`; the Apprise URL is built from it at send time
 * with every user-supplied component `rawurlencode()`d, and is never stored.
 */
interface MessagingChannelType extends ByoChannelType
{
    /**
     * The `config` keys a send cannot do without; the transport refuses a channel where any is
     * missing or blank.
     *
     * @return list<string>
     */
    public function requiredConfigKeys(): array;

    /**
     * @param  array<string, mixed>  $config
     */
    public function appriseUrl(array $config): string;

    /**
     * The Apprise URL one send goes out on: appriseUrl() unless the message itself changes it
     * (PagerDuty pages a confirmation code and a test at `info`). Empty when appriseUrl() is.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $meta
     */
    public function appriseUrlFor(array $config, array $meta): string;

    /**
     * Why this send must not go out, checked by MessagingTransport before every send, after the
     * URL is known to be untampered and before any Apprise HTTP; null when it may. For a check
     * that is not a stored server host (Bluesky verifies the account's hosting with its own
     * lookups). A fixed, secret-free text.
     *
     * @param  array<string, mixed>  $config
     */
    public function sendRefusal(array $config): ?string;

    /**
     * The subscriber-supplied server's base URL, vetted at save and again before every send,
     * or null for a type whose host is fixed.
     *
     * @param  array<string, mixed>  $config
     */
    public function serverUrl(array $config): ?string;

    /**
     * What makes two channels of this type the same destination (one channel per type and
     * destination). Never shown and never logged: it can hold a webhook token.
     *
     * @param  array<string, mixed>  $config
     */
    public function destinationKey(array $config): string;

    /**
     * What one destination of this type is called, for the duplicate refusal ("space",
     * "channel", "room").
     */
    public function destinationNoun(): string;

    /**
     * The text to send for this body, title and meta (alert, confirmation or test).
     *
     * @param  array<string, mixed>  $meta
     */
    public function compose(string $body, ?string $title, array $meta): string;

    /**
     * The separate title Apprise sends alongside compose()'s text, or null to send none (the
     * default: the title is then part of the text). Only a type whose destination has its own
     * title field returns one (IFTTT's value1), and its compose() then leaves the title out.
     * `$title` already carries the admin message prefix.
     *
     * @param  array<string, mixed>  $meta
     */
    public function appriseTitle(?string $title, array $meta): ?string;
}
