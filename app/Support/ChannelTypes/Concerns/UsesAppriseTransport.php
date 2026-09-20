<?php

namespace App\Support\ChannelTypes\Concerns;

use App\Enums\ChannelTransport;

trait UsesAppriseTransport
{
    public function transport(): ChannelTransport
    {
        return ChannelTransport::Apprise;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{apprise_url: string}
     */
    public function buildDestination(array $data): array
    {
        return ['apprise_url' => $this->buildAppriseUrl($data)];
    }

    public function supportsInlineCreate(): bool
    {
        return true;
    }

    public function isAvailable(): bool
    {
        return true;
    }
}
