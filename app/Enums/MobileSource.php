<?php

namespace App\Enums;

enum MobileSource: string
{
    case Traccar = 'traccar';

    public function label(): string
    {
        return match ($this) {
            self::Traccar => 'Traccar',
        };
    }
}
