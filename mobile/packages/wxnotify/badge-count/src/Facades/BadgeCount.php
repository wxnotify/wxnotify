<?php

namespace Wxnotify\BadgeCount\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static object|null set(int $count)
 * @method static string|null timezone()
 *
 * @see \Wxnotify\BadgeCount\BadgeCount
 */
class BadgeCount extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Wxnotify\BadgeCount\BadgeCount::class;
    }
}
