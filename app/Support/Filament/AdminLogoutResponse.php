<?php

namespace App\Support\Filament;

use Filament\Auth\Http\Responses\Contracts\LogoutResponse as Responsable;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Sends a subscriber logging out of the staff-only /admin panel to the
 * subscriber-facing /dashboard panel's login page instead of back to
 * /admin/login -- /admin accounts are staff-provisioned, not something a
 * logged-out visitor should be invited to sign back into from here.
 * Every other panel keeps Filament's default behavior of returning to its
 * own login page.
 */
class AdminLogoutResponse implements Responsable
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        if (Filament::getCurrentPanel()?->getId() === 'admin') {
            return redirect()->to(Filament::getPanel('dashboard')->getLoginUrl());
        }

        return redirect()->to(
            Filament::hasLogin() ? Filament::getLoginUrl() : Filament::getUrl(),
        );
    }
}
