<?php

namespace App\Filament\Dashboard\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Billing extends Page
{
    protected string $view = 'filament.dashboard.pages.billing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::CreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 3;

    public static function shouldRegisterNavigation(): bool
    {
        return config('wxnotify.mode') === 'cloud';
    }

    public static function canAccess(): bool
    {
        return config('wxnotify.mode') === 'cloud';
    }
}
