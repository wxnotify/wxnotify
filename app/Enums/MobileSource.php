<?php

namespace App\Enums;

enum MobileSource: string
{
    case Traccar = 'traccar';
    case App = 'app';

    public function label(): string
    {
        return match ($this) {
            self::Traccar => 'Traccar',
            self::App => 'WxNotify app',
        };
    }
}
