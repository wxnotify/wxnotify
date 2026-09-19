{{-- $wfo, $ttaaii, $productAwipsAbbr, $issuedAt (Carbon, UTC), $localIssuedAt (Carbon, WFO-local), $vtecAction, $vtecOffice, $vtecPhenomenon,
     $vtecSignificance, $vtecEventNumber, $vtecBegin (Carbon), $vtecEnd (Carbon),
     $productName, $officeCity, $ugcLine, $hazard, $hazardSource, $impact, $locationsImpacted,
     $callToAction, $polygonLine, $motionLine, $damageTagLines (array<string>) --}}
{{ strtoupper($ttaaii) }} K{{ $wfo }} {{ $issuedAt->format('dHi') }}
{{ strtoupper($productAwipsAbbr) }}{{ $wfo }}
{{ $ugcLine }}
/O.{{ $vtecAction }}.{{ $vtecOffice }}.{{ $vtecPhenomenon }}.{{ $vtecSignificance }}.{{ $vtecEventNumber }}.{{ $vtecBegin->format('ymd\THi\Z') }}-{{ $vtecEnd->format('ymd\THi\Z') }}/

BULLETIN - IMMEDIATE BROADCAST REQUESTED
{{ $productName }}
National Weather Service {{ $officeCity }}
{{ $localIssuedAt->format('gi A T D M j Y') }}

The National Weather Service in {{ $officeCity }} has issued a

* {{ $productName }}.

* Until {{ $localEnd->format('gi A T') }}.

* At {{ $localIssuedAt->format('gi A T') }}, this was located near the warned area.

  HAZARD...{{ $hazard }}

  SOURCE...{{ $hazardSource }}

  IMPACT...{{ $impact }}
@if($locationsImpacted)

* Locations impacted include...
  {{ $locationsImpacted }}
@endif

PRECAUTIONARY/PREPAREDNESS ACTIONS...

{{ $callToAction }}

&&

{{ $polygonLine }}
@if($motionLine)
{{ $motionLine }}
@endif
@if(count($damageTagLines) > 0)

@foreach($damageTagLines as $line)
{{ $line }}
@endforeach
@endif

$$
