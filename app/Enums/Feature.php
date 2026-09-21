<?php

namespace App\Enums;

enum Feature: string
{
    case HeadsUp = 'heads_up';

    /**
     * The boolean property on FeatureFlagsSettings that switches this feature.
     */
    public function settingsProperty(): string
    {
        return "{$this->value}_enabled";
    }
}
