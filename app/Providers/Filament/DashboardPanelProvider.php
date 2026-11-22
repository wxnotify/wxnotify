<?php

namespace App\Providers\Filament;

use App\Actions\Account\SignUpGate;
use App\Actions\Support\GenerateChatwootIdentityHash;
use App\Filament\Dashboard\Pages\Auth\EditProfile;
use App\Filament\Dashboard\Pages\Auth\Login;
use App\Filament\Dashboard\Pages\Auth\Register;
use App\Filament\Dashboard\Widgets\AlertsByCategoryChart;
use App\Filament\Dashboard\Widgets\AlertsPerDayChart;
use App\Filament\Dashboard\Widgets\AnnouncementsWidget;
use App\Filament\Dashboard\Widgets\RecentAlertsWidget;
use App\Http\Middleware\RedirectAdmins;
use App\Models\User;
use App\Support\Filament\RecoverableAppAuthentication;
use App\Support\Filament\RecoverablePasskeyAuthentication;
use App\Support\MaintenanceModeGuard;
use Awcodes\Gravatar\GravatarPlugin;
use CerealKiller97\FilamentBugReports\BugReportsPlugin;
use DutchCodingCompany\FilamentDeveloperLogins\FilamentDeveloperLoginsPlugin;
use DutchCodingCompany\FilamentSocialite\FilamentSocialitePlugin;
use DutchCodingCompany\FilamentSocialite\Provider;
use EightCedars\FilamentInactivityGuard\FilamentInactivityGuardPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Sentinel\SentinelPlugin;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Guava\FilamentKnowledgeBase\Plugins\KnowledgeBaseCompanionPlugin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use JeffersonGoncalves\FilamentServiceDesk\ServiceDeskUserPlugin;
use Laravel\Socialite\Contracts\User as SocialiteUserContract;
use STS\FilamentImpersonate\ImpersonateManager;
use Swis\Filament\Backgrounds\FilamentBackgroundsPlugin;
use Swis\Filament\Backgrounds\ImageProviders\MyImages;
use Wallacemartinss\FilamentOnboarding\FilamentOnboardingPlugin;

class DashboardPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('dashboard')
            ->path('dashboard')
            ->viteTheme('resources/css/filament/dashboard/theme.css')
            ->colors([
                'primary' => Color::Blue,
            ])
            ->navigationGroups([
                'Live Weather',
                'Alerting',
                'Tools',
                'Support',
            ])
            ->databaseNotifications()
            ->login(Login::class)
            ->registration(Register::class)
            ->passwordReset()
            ->emailVerification()
            ->profile(EditProfile::class)
            ->brandName(generateBrandName())
            ->multiFactorAuthentication([
                RecoverableAppAuthentication::make()->recoverable(),
                RecoverablePasskeyAuthentication::make(),
            ])
            ->discoverResources(in: app_path('Filament/Dashboard/Resources'), for: 'App\Filament\Dashboard\Resources')
            ->discoverPages(in: app_path('Filament/Dashboard/Pages'), for: 'App\Filament\Dashboard\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Dashboard/Widgets'), for: 'App\Filament\Dashboard\Widgets')
            ->widgets([
                AnnouncementsWidget::class,
                RecentAlertsWidget::class,
                AlertsPerDayChart::class,
                AlertsByCategoryChart::class,
                // AccountWidget::class,
                // FilamentInfoWidget::class,
            ])
            ->renderHook(
                // Registered before ->plugins() below: Filament renders
                // multiple hooks on the same name in registration order, and
                // KnowledgeBaseCompanionPlugin registers its own
                // SIDEBAR_FOOTER hook ("Documentation") the moment
                // ->plugins() runs. Registering this one first puts "Did You
                // Know?" above "Documentation" in the sidebar.
                PanelsRenderHook::SIDEBAR_FOOTER,
                fn (): View => view('filament.dashboard.did-you-know'),
            )
            ->renderHook(
                // Shown on every dashboard page while the signed-in subscriber's account is scheduled for deletion.
                PanelsRenderHook::PAGE_START,
                fn (): string => auth()->user()?->isPendingDeletion() ? view('filament.dashboard.account-deletion-banner')->render() : '',
            )
            ->renderHook(
                // The key-free map tile fallback (and provider logo), in <head> so it is installed before any
                // map, the Pinpoint location picker included, creates its tile layer. Signed-in pages only:
                // the sign-in pages have no map. The tile URL with the key is printed only by map pages.
                PanelsRenderHook::HEAD_END,
                fn (): string => auth()->check() ? view('filament.partials.map-tile-fallback')->render() : '',
            )
            ->renderHook(
                PanelsRenderHook::CONTENT_START,
                fn (): string => app(MaintenanceModeGuard::class)->isMaintenanceActive()
                    ? view('filament.dashboard.maintenance-banner', ['message' => app(MaintenanceModeGuard::class)->message()])->render()
                    : '',
            )
            ->plugins([
                FilamentSocialitePlugin::make()
                    ->providers([
                        Provider::make('google')
                            ->label('Google')
                            ->icon('fab-google')
                            ->color(Color::hex('#4285f4'))
                            ->outlined(false)
                            ->stateless(false),
                    ])
                    ->registration(fn (string $provider, SocialiteUserContract $oauthUser, ?Authenticatable $user): bool => $user !== null
                        || app(SignUpGate::class)->check(app(SignUpGate::class)->rememberedCode(), $oauthUser->getEmail())->allowed)
                    ->createUserUsing(function (string $provider, SocialiteUserContract $oauthUser, FilamentSocialitePlugin $plugin): Authenticatable {
                        $gate = app(SignUpGate::class);

                        $user = $gate->admit($gate->rememberedCode(), (string) $oauthUser->getEmail(), function () use ($oauthUser): User {
                            $user = User::create([
                                'name' => $oauthUser->getName() ?? $oauthUser->getNickname() ?? $oauthUser->getEmail(),
                                'email' => $oauthUser->getEmail(),
                                'password' => Str::password(40),
                            ]);

                            // Google (and Apple, once added) already verify the email address
                            // on their end — no reason to make the subscriber verify it again.
                            $user->markEmailAsVerified();

                            return $user;
                        });

                        $gate->forgetCode();

                        return $user;
                    }),
                KnowledgeBaseCompanionPlugin::make()
                    ->knowledgeBasePanelId('kb'),
                FilamentBackgroundsPlugin::make()
                    ->imageProvider(
                        MyImages::make()
                            ->directory('images/backgrounds/weather_wallpapers')
                    ),
                GravatarPlugin::make(),
                FilamentDeveloperLoginsPlugin::make()
                    ->enabled(app()->environment('local'))
                    ->users([
                        'Admin' => 'admin@host.com',
                        'User' => 'user@host.com',
                    ]),
                SentinelPlugin::make()
                    ->windowPosition('bottom-right')    // or ->fullPage() for a full-page 500
                    ->email('support@wxnotify.com'),    // "Contact support" mailto:
                FilamentInactivityGuardPlugin::make(),
                // ServiceDeskUserPlugin::make()
                //     ->knowledgeBase(false)
                //     ->serviceCatalog(false),
                // BugReportsPlugin::make()
                //     ->authorizeManagementUsing(fn (User $user): bool => (bool) $user->is_staff),
                FilamentOnboardingPlugin::make()
                    ->launcher()                        // floating checklist, on every page
                    ->tours()                           // guided tours
                    ->progressPage()                    // optional page laying the journey out
                    ->launcherPosition('bottom-right')  // bottom-left, top-right, top-left
                    ->modalPosition('center'),          // where images and videos open
            ])
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): ?string => $this->shouldShowSupportWidget()
                    ? Blade::render(
                        '@include(\'filament.dashboard.chatwoot-widget\')',
                        ['identityHash' => app(GenerateChatwootIdentityHash::class)(auth()->user())],
                    )
                    : null,
            )
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
                RedirectAdmins::class,
                Authenticate::class,
            ]);
    }

    /**
     * The support chat widget is for a signed-in subscriber in cloud mode with Chatwoot set up. It stays away while
     * staff are impersonating: the widget would identify them as the subscriber, so a message would go to Slack under
     * the subscriber's name and the subscriber's earlier conversation would be readable.
     */
    private function shouldShowSupportWidget(): bool
    {
        return auth()->check()
            && config('wxnotify.mode') === 'cloud'
            && filled(config('services.chatwoot.base_url'))
            && filled(config('services.chatwoot.website_token'))
            && ! app(ImpersonateManager::class)->isImpersonating();
    }
}
