<?php

namespace App\Filament\Admin\Resources\IngestedProducts\Pages;

use App\Filament\Admin\Resources\IngestedProducts\IngestedProductResource;
use App\Models\IngestedProduct;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Livewire\Attributes\Url;

class ListIngestedProducts extends ListRecords
{
    protected static string $resource = IngestedProductResource::class;

    /**
     * Only ever an id (kept in the URL as ?product=): the product is loaded again on every render.
     * Untyped because a hand-edited query string can carry anything; selectedProduct() validates it.
     */
    #[Url(as: 'product')]
    public int|string|null $selectedProductId = null;

    public function selectProduct(int|string $id): void
    {
        $this->selectedProductId = $id;
    }

    public function selectedProduct(): ?IngestedProduct
    {
        $id = filter_var($this->selectedProductId, FILTER_VALIDATE_INT);

        return $id === false ? null : IngestedProduct::query()->with('scenario')->find($id);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.admin.pages.ingested-product-pane')
                ->viewData(fn (): array => ['product' => $this->selectedProduct()]),
            $this->getTabsContentComponent(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
    }
}
