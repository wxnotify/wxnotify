<?php

namespace App\Filament\Admin\Pages;

use App\Actions\Setup\InstallReferenceData;
use App\Models\Product;
use App\Support\Setup\ReferenceDataStatus;
use BackedEnum;
use Database\Seeders\ProductCatalogSeeder;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Setup extends Page
{
    protected string $view = 'filament.admin.pages.setup';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Setup';

    protected static ?string $title = 'Setup';

    public static function canAccess(): bool
    {
        return auth()->user()?->is_owner === true;
    }

    /**
     * @return list<array{key: string, label: string, count: int, installed: bool}>
     */
    public function getSets(): array
    {
        return ReferenceDataStatus::sets();
    }

    /**
     * The names of the products the catalog install deletes, read from the seeder's own list so the
     * modal cannot drift from it. A code with no product row is shown as the code.
     */
    private function orphanProductNames(): string
    {
        $names = Product::query()->whereIn('code', ProductCatalogSeeder::ORPHAN_CODES)->pluck('name', 'code');

        return collect(ProductCatalogSeeder::ORPHAN_CODES)
            ->map(fn (string $code): string => $names[$code] ?? $code)
            ->implode(', ');
    }

    public function installReferenceDataAction(): Action
    {
        return Action::make('installReferenceData')
            ->label('Install reference data')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->disabled(fn (): bool => ReferenceDataStatus::allInstalled())
            ->requiresConfirmation()
            ->modalHeading('Install reference data')
            ->modalDescription(fn (): string => 'This installs the product catalog categories and descriptions, the "Did you know" tips and the tracked shapefile layers, but only the ones that are not installed yet. Nothing that already exists is changed. One caution: when the product catalog is not yet installed, the install removes '
                .count(ProductCatalogSeeder::ORPHAN_CODES).' legacy products that have no counterpart in the catalog ('.$this->orphanProductNames().'). Channel types are not touched.')
            ->modalSubmitActionLabel('Install')
            ->action(function (): void {
                abort_unless(self::canAccess(), 403);

                $ran = app(InstallReferenceData::class)();

                if ($ran === []) {
                    Notification::make()->title('Everything was already installed')->success()->send();

                    return;
                }

                $labels = collect(ReferenceDataStatus::sets())->pluck('label', 'key');

                Notification::make()
                    ->title('Installed: '.collect($ran)->map(fn (string $key): string => $labels[$key] ?? $key)->implode(', '))
                    ->success()
                    ->send();
            });
    }
}
