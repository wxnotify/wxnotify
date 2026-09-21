<?php

namespace App\Support;

use App\Exceptions\MaintenanceModeException;
use App\Settings\GeneralSettings;
use Spatie\LaravelSettings\Exceptions\MissingSettings;
use STS\FilamentImpersonate\ImpersonateManager;

class MaintenanceModeGuard
{
    public const DEFAULT_MESSAGE = 'The dashboard is temporarily read-only while we perform maintenance. Alerts continue to be delivered as normal.';

    public function message(): string
    {
        try {
            $message = app(GeneralSettings::class)->maintenance_message;
        } catch (MissingSettings) {
            return self::DEFAULT_MESSAGE;
        }

        return filled($message) ? $message : self::DEFAULT_MESSAGE;
    }

    public function isMaintenanceActive(): bool
    {
        try {
            return app(GeneralSettings::class)->maintenance_mode;
        } catch (MissingSettings) {
            return false;
        }
    }

    public function isBlocking(): bool
    {
        $user = auth()->user();

        if ($user === null || $user->is_staff) {
            return false;
        }

        if (app(ImpersonateManager::class)->isImpersonating()) {
            return false;
        }

        return $this->isMaintenanceActive();
    }

    public function assertWritable(): void
    {
        if ($this->isBlocking()) {
            throw new MaintenanceModeException($this->message());
        }
    }
}
