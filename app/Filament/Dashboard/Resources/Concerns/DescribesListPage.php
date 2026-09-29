<?php

namespace App\Filament\Dashboard\Resources\Concerns;

use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;

/**
 * Reproduces ListRecords::content()'s own default component set verbatim
 * (see vendor/filament/filament/src/Resources/Pages/ListRecords.php), just
 * inserting one muted-text explanation of the page's purpose before the
 * table -- for a new subscriber landing on a page that's otherwise just a
 * table and a "New" button. pageDescription() is the only thing each of
 * this trait's five users needs to supply.
 */
trait DescribesListPage
{
    abstract protected function pageDescription(): string;

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                Text::make($this->pageDescription())
                    ->color('gray'),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ]);
    }
}
