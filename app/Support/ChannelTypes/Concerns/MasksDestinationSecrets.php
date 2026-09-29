<?php

namespace App\Support\ChannelTypes\Concerns;

trait MasksDestinationSecrets
{
    /**
     * The last 4 characters of a secret, prefixed with a mask -- enough for
     * a subscriber to tell two channels of the same type apart without
     * exposing anything reusable.
     */
    private static function maskLast4(string $secret): string
    {
        return '••••'.mb_substr($secret, -4);
    }
}
