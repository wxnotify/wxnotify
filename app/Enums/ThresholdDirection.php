<?php

namespace App\Enums;

enum ThresholdDirection: string
{
    case Above = 'above';
    case Below = 'below';

    public function label(): string
    {
        return match ($this) {
            self::Above => 'Rises above',
            self::Below => 'Falls below',
        };
    }
}
