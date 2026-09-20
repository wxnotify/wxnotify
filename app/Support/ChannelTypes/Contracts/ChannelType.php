<?php

namespace App\Support\ChannelTypes\Contracts;

use App\Enums\ChannelTransport;
use Filament\Forms\Components\Field;

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
     * @return array<Field>
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
     * Whether this channel counts against Free's single no-cost-channel
     * slot. False for Email, which is unlimited at every tier, and for
     * BYO-key paid channels, which are locked to Free entirely (they
     * aren't reachable to pick in the first place, so this only matters
     * for the no-cost catalog).
     */
    public function countsTowardFreeSlot(): bool;
}
