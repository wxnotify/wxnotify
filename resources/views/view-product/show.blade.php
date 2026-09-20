@extends('view-product.layout')

@section('title', $title.($office ? " · {$office}" : '').' · WxNotify')

@section('head')
    <meta name="description" content="{{ $description }}">
    <meta name="robots" content="{{ $product->is_test ? 'noindex,nofollow' : 'index,follow' }}">
    <link rel="canonical" href="{{ $product->viewUrl() }}">
@endsection

@section('content')
    @if ($product->is_test)
        <div class="mb-6 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
            TEST PRODUCT — generated for testing, not a real weather product.
        </div>
    @endif

    <h1 class="text-2xl font-semibold">{{ $title }}</h1>

    @if (filled($product->headline))
        <p class="mt-2 text-slate-700">{{ $product->headline }}</p>
    @endif

    <dl class="mt-6 grid grid-cols-1 gap-x-8 gap-y-3 rounded-lg border border-slate-200 bg-white p-4 text-sm sm:grid-cols-2">
        @foreach ($details as $label => $value)
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</dt>
                <dd class="mt-0.5 font-medium">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    <pre class="mt-6 overflow-x-auto rounded-lg border border-slate-200 bg-white p-4 font-mono text-xs leading-relaxed whitespace-pre-wrap">{{ $product->text }}</pre>
@endsection
