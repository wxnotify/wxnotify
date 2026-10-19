<?php

namespace App\Providers\Filament;

use App\Filament\Admin\Widgets\AdminStatsOverview;
use App\Filament\Admin\Widgets\AlertRulesByCategoryChart;
use App\Models\User;
use Bityukov\CommandCenter\Filament\CommandCenterPlugin;
use CerealKiller97\FilamentBugReports\BugReportsPlugin;
// use EightCedars\FilamentInactivityGuard\FilamentInactivityGuardPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use JeffersonGoncalves\Filament\CookieConsent\CookieConsentPlugin;
use JeffersonGoncalves\Filament\OneTimeOperations\OneTimeOperationsPlugin;
use JeffersonGoncalves\FilamentServiceDesk\ServiceDeskAdminPlugin;
use ShuvroRoy\FilamentSpatieLaravelHealth\FilamentSpatieLaravelHealthPlugin;
use Wallacemartinss\FilamentOnboarding\FilamentOnboardingPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Teal,
            ])
            ->databaseNotifications()
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
            ->widgets([
                // AccountWidget::class,
                AdminStatsOverview::class,
                AlertRulesByCategoryChart::class,
            ])
            ->plugins([
                CommandCenterPlugin::make(),
                ServiceDeskAdminPlugin::make(),
                CookieConsentPlugin::make(),
                // FilamentInactivityGuardPlugin::make(),
                BugReportsPlugin::make()
                    ->authorizeManagementUsing(fn (User $user): bool => (bool) $user->is_staff),
                FilamentSpatieLaravelHealthPlugin::make()
                    ->navigationGroup('System')
                    ->navigationSort(10)
                    ->navigationIcon('heroicon-o-cpu-chip')
                    ->navigationLabel('Application Health'),
                OneTimeOperationsPlugin::make(),
                // The owner setup checklist (flow "owner-setup", shipped by migration). Owners only:
                // nobody else gets the launcher, and nothing of onboarding is read on their requests.
                // The progress page is App\Filament\Admin\Pages\SetupChecklist (owner-only), not
                // ->progressPage(); the flow and condition resources are owner-only through
                // config('filament-onboarding.policies').
                FilamentOnboardingPlugin::make()
                    ->manageFlows()
                    ->launcher()
                    ->progressPageNavigation(label: 'Setup checklist', icon: 'heroicon-o-check-badge', group: 'System')
                    ->tours(false)
                    ->skipWhen(fn (User $user): bool => ! $user->is_owner)
                    ->navigationGroup('System')
                    ->navigationIcon('heroicon-o-map')
                    ->navigationSort(70),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
