<?php

namespace Wxnotify\BadgeCount;

use Illuminate\Support\ServiceProvider;

class BadgeCountServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BadgeCount::class, fn (): BadgeCount => new BadgeCount);
    }
}
