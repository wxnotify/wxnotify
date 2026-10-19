<?php

namespace App\Filament\Admin\Pages;

use Wallacemartinss\FilamentOnboarding\Pages\OnboardingProgress;

/**
 * The plugin's progress page ("Setup checklist", /admin/onboarding), restricted to the owner. The
 * plugin's own page has no access check, so the panel registers this one instead of calling
 * ->progressPage(); its label, icon, group and slug still come from the plugin's settings.
 */
class SetupChecklist extends OnboardingProgress
{
    protected static bool $isDiscovered = true;

    public static function canAccess(): bool
    {
        return auth()->user()?->is_owner === true;
    }
}
