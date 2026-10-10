<?php

namespace App\Support\Concerns;

/**
 * The Settings tab's menu. A bottom-bar item cannot carry a dropdown, so tapping Settings opens a
 * bottom sheet (partials/tabs.blade.php) offering Rules, Locations and Account. Each of those screens,
 * and Alerts, uses this trait so the sheet can be opened from whichever tab the subscriber is on.
 */
trait OpensSettingsMenu
{
    public bool $settingsMenuOpen = false;

    public function openSettingsMenu(): void
    {
        $this->settingsMenuOpen = true;
    }

    public function closeSettingsMenu(): void
    {
        $this->settingsMenuOpen = false;
    }

    public function openRules(): void
    {
        $this->goToSettingsPage('/rules');
    }

    public function openLocations(): void
    {
        $this->goToSettingsPage('/locations');
    }

    public function openAccount(): void
    {
        $this->goToSettingsPage('/account');
    }

    /**
     * Replaces the current tab screen, as a bottom-bar tap does, so switching never grows the back stack.
     * Choosing the page already shown just closes the sheet.
     */
    private function goToSettingsPage(string $uri): void
    {
        $this->settingsMenuOpen = false;

        if ($this->settingsPage() !== $uri) {
            $this->replace($uri);
        }
    }

    /**
     * The URI this screen is a tab page of; null for Alerts.
     */
    protected function settingsPage(): ?string
    {
        return null;
    }
}
