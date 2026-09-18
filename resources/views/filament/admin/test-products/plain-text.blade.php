{{-- $ttaaii, $wfo, $issuedAt (Carbon), $productName, $officeCity, $ugcLine (nullable), $bodyText --}}
{{ strtoupper($ttaaii) }} K{{ $wfo }} {{ $issuedAt->format('dHi') }}

{{ strtoupper($productName) }}
NATIONAL WEATHER SERVICE {{ strtoupper($officeCity) }}
{{ $issuedAt->format('g:i A T D M j Y') }}
@if($ugcLine)

{{ $ugcLine }}
@endif

{{ $bodyText }}

$$
