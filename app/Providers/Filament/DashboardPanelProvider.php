<?php

namespace App\Providers\Filament;

use App\Actions\Support\GenerateChatwootIdentityHash;
use App\Filament\Dashboard\Pages\Auth\EditProfile;
use App\Filament\Dashboard\Pages\Auth\Register;
use App\Filament\Dashboard\Widgets\AnnouncementsWidget;
use App\Http\Middleware\RedirectAdmins;
use App\Models\User;
use Awcodes\Gravatar\GravatarPlugin;
use DutchCodingCompany\FilamentDeveloperLogins\FilamentDeveloperLoginsPlugin;
use DutchCodingCompany\FilamentSocialite\FilamentSocialitePlugin;
use DutchCodingCompany\FilamentSocialite\Provider;
use EightCedars\FilamentInactivityGuard\FilamentInactivityGuardPlugin;
use Filament\Auth\MultiFactor\App\AppAuthentication;
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
use Swis\Filament\Backgrounds\FilamentBackgroundsPlugin;
use Swis\Filament\Backgrounds\ImageProviders\MyImages;

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
            ->databaseNotifications()
            ->login()
            ->registration(Register::class)
            ->passwordReset()
            ->emailVerification()
            ->profile(EditProfile::class)
            ->brandName(generateBrandName())
            ->multiFactorAuthentication([
                AppAuthentication::make(),
            ])
            ->discoverResources(in: app_path('Filament/Dashboard/Resources'), for: 'App\Filament\Dashboard\Resources')
            ->discoverPages(in: app_path('Filament/Dashboard/Pages'), for: 'App\Filament\Dashboard\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Dashboard/Widgets'), for: 'App\Filament\Dashboard\Widgets')
            ->widgets([
                AnnouncementsWidget::class,
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
                    ->registration()
                    ->createUserUsing(function (string $provider, SocialiteUserContract $oauthUser, FilamentSocialitePlugin $plugin): Authenticatable {
                        $user = User::create([
                            'name' => $oauthUser->getName() ?? $oauthUser->getNickname() ?? $oauthUser->getEmail(),
                            'email' => $oauthUser->getEmail(),
                            'password' => Str::password(40),
                        ]);

                        // Google (and Apple, once added) already verify the email address
                        // on their end — no reason to make the subscriber verify it again.
                        $user->markEmailAsVerified();

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
                ServiceDeskUserPlugin::make()
                    ->knowledgeBase(false)
                    ->serviceCatalog(false),
            ])
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): ?string => (auth()->check() && config('wxnotify.mode') === 'cloud')
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
}
