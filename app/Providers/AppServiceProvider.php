<?php

namespace App\Providers;

use App\Contracts\PipelineStack;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\User;
use App\Notifications\PrefixedResetPassword;
use App\Notifications\PrefixedVerifyEmail;
use App\Notifications\PrefixedVerifyEmailChange;
use App\Observers\ActivityLogObserver;
use App\Observers\IngestedProductObserver;
use App\Observers\LocationZoneObserver;
use App\Observers\UserActivityObserver;
use App\Support\ApplyEmailSettings;
use App\Support\ClientIp;
use App\Support\Filament\AdminLogoutResponse;
use App\Support\Pipeline\UnmanagedPipelineStack;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Auth\Notifications\VerifyEmail;
use Filament\Auth\Notifications\VerifyEmailChange;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Support\CauserResolver;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\DatabaseConnectionCountCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\OptimizedAppCheck;
use Spatie\Health\Checks\Checks\PingCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;
use STS\FilamentImpersonate\ImpersonateManager;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LogoutResponse::class, AdminLogoutResponse::class);
        $this->app->bind(PipelineStack::class, UnmanagedPipelineStack::class);

        // Lets the message-prefix setting reach account emails too, with no
        // changes to Filament's own Register/ResetPassword/EditProfile pages
        // -- they all resolve these classes through the container.
        $this->app->bind(VerifyEmail::class, PrefixedVerifyEmail::class);
        $this->app->bind(ResetPassword::class, PrefixedResetPassword::class);
        $this->app->bind(VerifyEmailChange::class, PrefixedVerifyEmailChange::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->make(ApplyEmailSettings::class)();

        ClientIp::configureProxies();

        RateLimiter::for('app-api', fn (Request $request) => Limit::perMinute(120)
            ->by('app-api:'.($request->user()?->currentAccessToken()?->id ?? $request->ip())));

        RateLimiter::for('app-auth', fn (Request $request) => Limit::perMinute(10)
            ->by('app-auth:'.(ClientIp::for($request) ?? $request->ip())));

        RateLimiter::for('app-authorize', fn (Request $request) => Limit::perMinute(20)
            ->by('app-authorize:'.($request->user()?->id ?? ClientIp::for($request) ?? $request->ip())));

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

        Gate::define('command-center:access', fn ($user) => $user->is_staff);
        Gate::define('command-center:manage-commands', fn ($user) => $user->is_staff);
        Gate::define('command-center:prune-history', fn ($user) => $user->is_staff);

        Health::checks([
            OptimizedAppCheck::new(),
            DebugModeCheck::new(),
            EnvironmentCheck::new(),
            ScheduleCheck::new(),
            UsedDiskSpaceCheck::new(),
            DatabaseCheck::new(),
            CacheCheck::new(),
            PingCheck::new()->url('https://wxnotify.com')
                ->name('Ping wxnotify.com'),
            DatabaseConnectionCountCheck::new(),
        ]);
    }
}
