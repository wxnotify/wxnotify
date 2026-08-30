<?php

namespace App\Enums;

enum ThresholdTimeframe: string
{
    case Day = 'day';
    case Overnight = 'overnight';
    case AnyTime = 'any_time';

    public function label(): string
    {
        return match ($this) {
            self::Day => 'During the day',
            self::Overnight => 'Overnight',
            self::AnyTime => 'Any time',
        };
    }
}
