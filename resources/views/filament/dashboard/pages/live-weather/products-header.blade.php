<header class="fi-header @if ($breadcrumbs) fi-header-has-breadcrumbs @endif">
    <div>
        @if ($breadcrumbs)
            <x-filament::breadcrumbs :breadcrumbs="$breadcrumbs" />
        @endif

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::PAGE_HEADER_HEADING_BEFORE, scopes: $this->getRenderHookScopes()) }}

        <div class="flex flex-wrap items-center gap-x-3 gap-y-2" data-page-title-row>
            <h1 class="fi-header-heading">
                {{ $heading }}
            </h1>
            <div data-page-title-slot wire:ignore></div>
        </div>

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::PAGE_HEADER_HEADING_AFTER, scopes: $this->getRenderHookScopes()) }}
    </div>
</header>
