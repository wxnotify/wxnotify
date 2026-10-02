<?php

namespace App\Support\ChannelTypes\Contracts;

use App\Enums\ChannelTransport;
use App\Models\NotificationChannel;
use Filament\Schemas\Components\Component;

interface ChannelType
{
    public function slug(): string;

    public function label(): string;

    /**
     * A Filament icon string (e.g. a blade-fontawesome 'fab-*' name, or a
     * Heroicon) — rendered via <x-filament::icon>.
     */
    public function icon(): string;

    /**
     * The guided form's setup step. Mostly Fields, but a type may include
     * presentational components (a Callout explaining its payload, say).
     *
     * @return array<Component>
     */
    public function formSchema(): array;

    /**
     * The delivery transport this channel type sends through.
     */
    public function transport(): ChannelTransport;

    /**
     * Build the stored destination from the guided form's data.
     *
     * @param  array<string, mixed>  $data
     * @return array{apprise_url?: string, config?: array<string, mixed>, label?: string}
     */
    public function buildDestination(array $data): array;

    /**
     * Whether the inline "add a channel" action in the alert wizard can
     * create this type.
     */
    public function supportsInlineCreate(): bool;

    /**
     * Whether this type is currently offered in the picker.
     */
    public function isAvailable(): bool;

    /**
     * Whether this channel can render a rich (HTML, inline images) message.
     * Only such channels receive the Daily Weather Summary's Full format;
     * every other type receives the Brief text.
     */
    public function supportsRichContent(): bool;

    /**
     * Whether this channel counts against Free's single no-cost-channel
     * slot. False for Email, which is unlimited at every tier, and for
     * BYO-key paid channels, which are locked to Free entirely (they
     * aren't reachable to pick in the first place, so this only matters
     * for the no-cost catalog).
     */
    public function countsTowardFreeSlot(): bool;

    /**
     * A short, at-a-glance identifier for this channel's destination, safe to
     * show in the subscriber-facing table — e.g. the email address, or a
     * masked fragment of a secret the channel type never surfaces in full.
     * Must never return a value that alone grants delivery access (a full
     * webhook URL, bot token, API key, etc.).
     */
    public function displaySummary(NotificationChannel $record): string;
}
