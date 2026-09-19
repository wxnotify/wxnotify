{{-- $ttaaii, $wfo, $issuedAt (Carbon, UTC), $localIssuedAt (Carbon, WFO-local), $productName, $officeCity, $ugcLine (nullable), $bodyText --}}
{{ strtoupper($ttaaii) }} K{{ $wfo }} {{ $issuedAt->format('dHi') }}

{{ strtoupper($productName) }}
NATIONAL WEATHER SERVICE {{ strtoupper($officeCity) }}
{{ $localIssuedAt->format('gi A T D M j Y') }}
@if($ugcLine)

{{ $ugcLine }}
@endif

{{ $bodyText }}

$$
