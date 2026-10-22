<?php

namespace App\Support\ChannelTypes\Concerns;

use App\Enums\ChannelTransport;
use App\Models\User;
use App\Support\Delivery\MessagingMessage;
use InvalidArgumentException;

/**
 * The shared shape of a MessagingChannelType: the destination in `config` (never
 * `apprise_url`), the type's label as the channel's label, chat-style composition through
 * MessagingMessage, and the fixed behaviour every messaging type shares: sent through
 * ChannelTransport::Messaging, dashboard-only (the wizard's BeginChannelConnection is the one
 * way in: no inline create, no app setup), plain text, and gated by the catalog's `min_tier`
 * rather than Free's no-cost slot.
 */
trait IsMessagingChannel
{
    /**
     * The `config` to store, from the guided form's data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when the data does not describe a usable destination
     */
    abstract protected function configFromForm(array $data): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array{config: array<string, mixed>, label: string}
     */
    public function buildDestination(array $data): array
    {
        return [
            'config' => $this->configFromForm($data),
            'label' => $this->label(),
        ];
    }

    public function transport(): ChannelTransport
    {
        return ChannelTransport::Messaging;
    }

    /**
     * The longest message the destination takes, in characters.
     */
    public function maxLength(): int
    {
        return 4000;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function compose(string $body, ?string $title, array $meta): string
    {
        return MessagingMessage::chat($body, $title, $meta, $this->maxLength());
    }

    /**
     * The Apprise URL arguments every subscriber-supplied server carries: no redirects (a
     * public server must not bounce Apprise to a private address), a 5 s connect and 10 s read
     * timeout. Extras are appended (Matrix's `discovery=no`, say).
     *
     * @param  array<string, string|int>  $extra
     */
    public function guardedQuery(array $extra = []): string
    {
        return http_build_query(['redirect' => 'no', 'cto' => 5, 'rto' => 10, ...$extra], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The alert form's inline create bypasses BeginChannelConnection.
     */
    public function supportsInlineCreate(): bool
    {
        return false;
    }

    /**
     * Messaging channels are created on the dashboard and then appear in the app.
     *
     * @return null
     */
    public function appSetupFields(User $user): ?array
    {
        return null;
    }

    public function supportsRichContent(): bool
    {
        return false;
    }

    /**
     * Messaging channels are gated by the catalog's `min_tier` instead.
     */
    public function countsTowardFreeSlot(): bool
    {
        return false;
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
