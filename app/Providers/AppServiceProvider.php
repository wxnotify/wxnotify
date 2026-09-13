<?php

namespace App\Providers;

use App\Listeners\ActivityAlertEventSubscriber;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\User;
use App\Observers\ActivityLogObserver;
use App\Observers\IngestedProductObserver;
use App\Observers\LocationZoneObserver;
use App\Observers\UserActivityObserver;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Support\CauserResolver;
use STS\FilamentImpersonate\ImpersonateManager;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Without this, activity logged while an admin impersonates a
        // subscriber attributes to the subscriber (Auth::user() during
        // impersonation), not the admin who is actually acting — defeating
        // the audit log's whole point of keeping impersonated actions
        // accountable.
        $this->app->make(CauserResolver::class)->resolveUsing(function () {
            $impersonate = $this->app->make(ImpersonateManager::class);

            return $impersonate->isImpersonating()
                ? $impersonate->getImpersonator()
                : auth()->user();
        });

        Activity::observe(ActivityLogObserver::class);
        User::observe(UserActivityObserver::class);
        IngestedProduct::observe(IngestedProductObserver::class);
        Location::observe(LocationZoneObserver::class);
        Event::subscribe(ActivityAlertEventSubscriber::class);

        Gate::define('command-center:access', fn ($user) => $user->is_staff);
        Gate::define('command-center:manage-commands', fn ($user) => $user->is_staff);
        Gate::define('command-center:prune-history', fn ($user) => $user->is_staff);
    }
}
