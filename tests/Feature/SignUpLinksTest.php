<?php

use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Dashboard\Pages\Auth\Login;
use App\Models\User;
use App\Settings\SignUpSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

use function Pest\Livewire\livewire;

function setLinksPolicy(string $policy): void
{
    $settings = app(SignUpSettings::class);
    $settings->policy = $policy;
    $settings->save();
}

it('shows the register action on the login page unless sign-ups are closed', function (string $policy, bool $shown) {
    setLinksPolicy($policy);

    $response = $this->get(Filament::getLoginUrl())->assertOk();

    $shown
        ? $response->assertSee(route('filament.dashboard.auth.register'))
        : $response->assertDontSee(route('filament.dashboard.auth.register'));
})->with([
    'open' => ['open', true],
    'invite only' => ['invite_only', true],
    'closed' => ['closed', false],
]);

it('shows the register link on the welcome page unless sign-ups are closed', function (string $policy, bool $shown) {
    setLinksPolicy($policy);

    // The welcome view is not routed (GET / redirects to the login page) and the app
    // has no `login` or `register` route, so give the view both to link to.
    Route::get('/login-stub', fn () => 'login')->name('login');
    Route::get('/register-stub', fn () => 'register')->name('register');
    Route::getRoutes()->refreshNameLookups();

    $html = view('welcome')->render();

    $shown
        ? expect($html)->toContain('Register')
        : expect($html)->not->toContain('Register');
})->with([
    'open' => ['open', true],
    'invite only' => ['invite_only', true],
    'closed' => ['closed', false],
]);

it('lets staff create a user and existing users sign in while closed', function () {
    setLinksPolicy('closed');

    $admin = User::factory()->create(['is_staff' => true]);
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    livewire(CreateUser::class)
        ->fillForm([
            'name' => 'Staff Made',
            'email' => 'staffmade@example.com',
            'password_setup' => 'send_reset_link',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(User::where('email', 'staffmade@example.com')->exists())->toBeTrue();

    auth()->logout();
    $existing = User::factory()->create(['password' => 'Sup3r$ecret!']);
    Filament::setCurrentPanel('dashboard');

    livewire(Login::class)
        ->fillForm(['email' => $existing->email, 'password' => 'Sup3r$ecret!'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth()->id())->toBe($existing->id);
});

it('keeps the multi-factor subheading mid-challenge when sign-ups are closed', function () {
    setLinksPolicy('closed');
    Filament::setCurrentPanel('dashboard');

    $component = livewire(Login::class)->instance();

    expect($component->getSubheading())->toBeNull();

    $component->userUndertakingMultiFactorAuthentication = 'encrypted-user-reference';

    expect($component->getSubheading())->toBe(__('filament-panels::auth/pages/login.multi_factor.subheading'));
});
