<?php

namespace App\Enums;

enum MessageBodyStyle: string
{
    case Full = 'full';
    case Brief = 'brief';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full',
            self::Brief => 'Brief',
        };
    }
}
