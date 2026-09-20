@extends('view-product.layout')

@section('title', $title.($office ? " · {$office}" : '').' · WxNotify')

@section('head')
    <meta name="description" content="{{ $description }}">
    <meta name="robots" content="{{ $product->is_test ? 'noindex,nofollow' : 'index,follow' }}">
    <link rel="canonical" href="{{ $product->viewUrl() }}">
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

    <x-filament::section heading="Product details" class="mt-6">
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
    </x-filament::section>

    <x-filament::section heading="Product text" class="mt-6">
        <pre class="overflow-x-auto font-mono text-xs leading-relaxed whitespace-pre-wrap text-gray-950 dark:text-white">{{ $product->text }}</pre>
    </x-filament::section>
@endsection
