<?php

namespace App\Filament\Admin\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class TranslationReview extends Page
{
    protected string $view = 'filament.admin.pages.translation-review';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Translation Review';
}
