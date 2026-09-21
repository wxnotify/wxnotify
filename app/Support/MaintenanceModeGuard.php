<?php

namespace App\Support;

use App\Exceptions\MaintenanceModeException;
use App\Settings\GeneralSettings;
use STS\FilamentImpersonate\ImpersonateManager;

class MaintenanceModeGuard
{
    public const DEFAULT_MESSAGE = 'The dashboard is temporarily read-only while we perform maintenance. Alerts continue to be delivered as normal.';

    public function message(): string
    {
        return filled($message = app(GeneralSettings::class)->maintenance_message) ? $message : self::DEFAULT_MESSAGE;
    }

    public function isMaintenanceActive(): bool
    {
        return app(GeneralSettings::class)->maintenance_mode;
    }

    public function isBlocking(): bool
    {
        if (! $this->isMaintenanceActive()) {
            return false;
        }

        $user = auth()->user();

        return $user !== null
            && ! $user->is_staff
            && ! app(ImpersonateManager::class)->isImpersonating();
    }

    public function assertWritable(): void
    {
        if ($this->isBlocking()) {
            throw new MaintenanceModeException($this->message());
        }
    }
}
