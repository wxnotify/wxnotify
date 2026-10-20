<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Native\Mobile\Providers\BrowserServiceProvider;
use Native\Mobile\Providers\GeolocationServiceProvider;
use Native\Mobile\Providers\NetworkServiceProvider;
use Native\Mobile\Providers\PushNotificationsServiceProvider;
use Native\Mobile\Providers\SecureStorageServiceProvider;
use Native\Mobile\UI\NativeUIServiceProvider;
use NativePHP\BackgroundTasks\BackgroundTasksServiceProvider;
use NativePHP\LocalNotifications\LocalNotificationsServiceProvider;
use Wxnotify\BadgeCount\BadgeCountServiceProvider;

class NativeServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * The NativePHP plugins to enable.
     *
     * Only plugins listed here will be compiled into your native builds.
     * This is a security measure to prevent transitive dependencies from
     * automatically registering plugins without your explicit consent.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public function plugins(): array
    {
        return [
            GeolocationServiceProvider::class,
            SecureStorageServiceProvider::class,
            PushNotificationsServiceProvider::class,
            BackgroundTasksServiceProvider::class,
            LocalNotificationsServiceProvider::class,
            NetworkServiceProvider::class,
            NativeUIServiceProvider::class,
            BrowserServiceProvider::class,
            BadgeCountServiceProvider::class,

        ];
    }
}
