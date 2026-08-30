<?php

namespace App\Support\ChannelTypes\Contracts;

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
     * Build the Apprise URL for this channel from the guided form's data.
     *
     * @param  array<string, mixed>  $data
     */
    public function buildAppriseUrl(array $data): string;

    /**
     * Whether this channel counts against Free's single no-cost-channel
     * slot. False for Email, which is unlimited at every tier, and for
     * BYO-key paid channels, which are locked to Free entirely (they
     * aren't reachable to pick in the first place, so this only matters
     * for the no-cost catalog).
     */
    public function countsTowardFreeSlot(): bool;
}
