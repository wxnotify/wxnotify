@extends('view-product.layout')

@section('title', $title.($office ? " · {$office}" : '').' · WxNotify')

@section('head')
    <meta name="description" content="{{ $description }}">
    <meta name="robots" content="{{ $product->is_test ? 'noindex,nofollow' : 'index,follow' }}">
    <link rel="canonical" href="{{ $product->viewUrl() }}">
    {{-- The page loads no scripts (it is cached as a static page), so the collapsible Product details section is a native <details> element. --}}
    <style>
        details.fi-section > summary { cursor: pointer; list-style: none; }
        details.fi-section > summary::-webkit-details-marker { display: none; }
        details.fi-section > summary .product-details-chevron { flex: none; margin-inline-start: auto; transition: transform 150ms; }
        details.fi-section[open] > summary .product-details-chevron { transform: rotate(180deg); }
    </style>
@endsection

@section('content')
    @if ($product->is_test)
        <x-filament::callout
            color="warning"
            icon="heroicon-o-exclamation-triangle"
            heading="TEST PRODUCT"
            description="Generated for testing, not a real weather product."
            class="mb-6"
        />
    @endif

    <h1 class="text-2xl font-semibold text-gray-950 dark:text-white">{{ $title }}</h1>

    @if (filled($product->headline))
        <p class="mt-2 text-gray-700 dark:text-gray-300">{{ $product->headline }}</p>
    @endif

    <details class="fi-section fi-section-has-header fi-collapsible mt-6" data-product-details>
        <summary class="fi-section-header">
            <div class="fi-section-header-text-ctn">
                <h3 class="fi-section-header-heading">Product details</h3>
            </div>
            <svg class="product-details-chevron text-gray-400 dark:text-gray-500" width="20" height="20" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
            </svg>
        </summary>
        <div class="fi-section-content-ctn">
            <div class="fi-section-content">
                <dl class="grid grid-cols-1 gap-x-8 gap-y-4 text-sm sm:grid-cols-2">
                    @foreach ($details as $label => $value)
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="mt-1 font-medium text-gray-950 dark:text-white">
                                @if ($label === 'Source')
                                    <x-filament::badge>{{ $value }}</x-filament::badge>
                                @else
                                    {{ $value }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>
    </details>

    <x-filament::section heading="Product text" class="mt-6">
        <pre class="overflow-x-auto font-mono text-xs leading-relaxed whitespace-pre-wrap text-gray-950 dark:text-white">{{ $product->text }}</pre>
    </x-filament::section>
@endsection
