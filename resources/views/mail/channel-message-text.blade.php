{{ config('app.name') }}

{!! $messageBody !!}
@isset($viewUrl)

View this product online: {{ $viewUrl }}
@endisset
