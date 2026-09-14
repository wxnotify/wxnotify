<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\DashboardPanelProvider;
use App\Providers\Filament\KbPanelProvider;
use App\Providers\Filament\SupportagentPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    DashboardPanelProvider::class,
    KbPanelProvider::class,
    SupportagentPanelProvider::class,
];
