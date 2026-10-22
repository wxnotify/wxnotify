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
}
