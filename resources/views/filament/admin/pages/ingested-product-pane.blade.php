@php
    use App\Filament\Admin\Resources\IngestedProducts\IngestedProductResource;
    use App\Filament\Admin\Resources\TestProductScenarios\TestProductScenarioResource;
@endphp
{{-- position: sticky only holds inside its parent's box, so the page grid cell that wraps this pane is the sticky element, not the pane itself. --}}
<style>
    .fi-grid-col:has(> .fi-sc-component > #ingested-product-pane) {
        position: sticky;
        top: 4.5rem;
        z-index: 10;
    }

    /* Filament ships no utility CSS for this panel's custom classes, so the selected row is tinted from the text colour, which suits both themes. */
    .ingested-product-row-selected {
        background-color: color-mix(in srgb, currentColor 9%, transparent);
    }
</style>
<div id="ingested-product-pane">
    <x-filament::section>
        @if ($product === null)
            <p style="margin: 0; opacity: 0.6;">Select a product below to view its contents.</p>
        @else
            <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; margin-bottom: 0.75rem;">
                <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem;">
                    <span style="font-weight: 600; font-size: 1rem;">{{ strtoupper((string) $product->awipsid) ?: 'Product #'.$product->id }} · {{ strtoupper((string) $product->cccc) ?: '—' }}</span>
                    @if ($product->is_test)
                        <x-filament::badge color="warning" size="sm">Test</x-filament::badge>
                    @endif
                    <x-filament::badge color="gray" size="sm">{{ $product->source }}</x-filament::badge>
                </div>
                <a href="{{ IngestedProductResource::getUrl('view', ['record' => $product]) }}" style="font-size: 0.875rem; text-decoration: underline;">Open full page</a>
            </div>

            <dl style="display: flex; flex-wrap: wrap; gap: 0.25rem 1.5rem; margin: 0 0 0.75rem 0; font-size: 0.8125rem;">
                <div><dt style="display: inline; opacity: 0.6;">Office</dt> <dd style="display: inline; margin: 0;">{{ $product->cccc ?? '—' }}</dd></div>
                <div><dt style="display: inline; opacity: 0.6;">TTAAII</dt> <dd style="display: inline; margin: 0;">{{ $product->ttaaii ?? '—' }}</dd></div>
                <div><dt style="display: inline; opacity: 0.6;">AWIPS ID</dt> <dd style="display: inline; margin: 0;">{{ $product->awipsid ?? '—' }}</dd></div>
                <div><dt style="display: inline; opacity: 0.6;">Issued (DDHHMM)</dt> <dd style="display: inline; margin: 0;">{{ $product->wmo_ddhhmm ?? '—' }}</dd></div>
                <div><dt style="display: inline; opacity: 0.6;">Received</dt> <dd style="display: inline; margin: 0;">{{ $product->received_at?->toDayDateTimeString() ?? '—' }}</dd></div>
                @if ($product->is_test && $product->test_product_scenario_id !== null && TestProductScenarioResource::canAccess())
                    <div>
                        <dt style="display: inline; opacity: 0.6;">Test scenario</dt>
                        <dd style="display: inline; margin: 0;"><a href="{{ TestProductScenarioResource::getUrl('edit', ['record' => $product->test_product_scenario_id]) }}" style="text-decoration: underline;">{{ $product->scenario?->name ?? 'Scenario #'.$product->test_product_scenario_id }}</a></dd>
                    </div>
                @endif
                @if ($product->replay_of_id !== null)
                    <div>
                        <dt style="display: inline; opacity: 0.6;">Replay of</dt>
                        <dd style="display: inline; margin: 0;"><a href="{{ IngestedProductResource::getUrl('view', ['record' => $product->replay_of_id]) }}" style="text-decoration: underline;">Product #{{ $product->replay_of_id }}</a></dd>
                    </div>
                @endif
            </dl>

            <pre style="margin: 0; max-height: 32vh; overflow: auto; white-space: pre-wrap; word-wrap: break-word; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 0.75rem; line-height: 1.5;">{{ $product->text }}</pre>
        @endif
    </x-filament::section>
</div>
