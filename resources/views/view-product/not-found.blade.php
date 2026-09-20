@extends('view-product.layout')

@section('title', 'Product not found · WxNotify')

@section('head')
    <meta name="robots" content="noindex">
@endsection

@section('content')
    <x-filament::empty-state
        heading="Product not found"
        description="We couldn't find a weather product at this address."
        icon="heroicon-o-magnifying-glass"
    />
@endsection
