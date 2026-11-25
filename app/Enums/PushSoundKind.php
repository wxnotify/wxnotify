<?php

namespace App\Enums;

enum PushSoundKind: string
{
    case ShortFuseWarning = 'short_fuse_warning';
    case OtherWarning = 'other_warning';
    case ConvectiveWatch = 'convective_watch';
    case OtherWatch = 'other_watch';
    case Advisory = 'advisory';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ShortFuseWarning => 'Short-fuse warnings',
            self::OtherWarning => 'Other warnings',
            self::ConvectiveWatch => 'Convective watches',
            self::OtherWatch => 'Other watches',
            self::Advisory => 'Advisories',
            self::Other => 'Other',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ShortFuseWarning => 'Tornado, Severe Thunderstorm, Flash Flood and other short-fuse warnings',
            self::OtherWarning => 'Every other warning, such as Winter Storm and Hurricane warnings',
            self::ConvectiveWatch => 'Tornado and Severe Thunderstorm watches',
            self::OtherWatch => 'Every other watch, such as Flash Flood and Winter Storm watches',
            self::Advisory => 'Advisories, such as Wind and Dense Fog advisories',
            self::Other => 'Statements, SPC and WPC products, daily summaries and outlook reports',
        };
    }

    /**
     * Lower is more urgent; an alert covering several kinds uses the lowest number.
     */
    public function priority(): int
    {
        return match ($this) {
            self::ShortFuseWarning => 0,
            self::OtherWarning => 1,
            self::ConvectiveWatch => 2,
            self::OtherWatch => 3,
            self::Advisory => 4,
            self::Other => 5,
        };
    }
}
