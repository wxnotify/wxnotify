{{-- $ttaaii, $wfo (SPC/WPC's own 3-letter office, e.g. WNS), $issuedAt (Carbon, UTC), $localIssuedAt (Carbon, WFO-local), $discussionNumber,
     $productLabel (e.g. "MESOSCALE DISCUSSION"), $officeName, $areasAffected, $ugcLine,
     $discussionText, $concerning, $polygonLine, $attnWfos (array<string>) --}}
{{ strtoupper($ttaaii) }} K{{ $wfo }} {{ $issuedAt->format('dHi') }}
SWOMCD
SPC MCD {{ $issuedAt->format('dHi') }}
{{ $ugcLine }}

{{ $productLabel }} {{ $discussionNumber }}
{{ $officeName }}
{{ $localIssuedAt->format('gi A T D M j Y') }}

@if ($areasAffected !== '')
AREAS AFFECTED...{{ $areasAffected }}

@endif
@if ($concerning !== '')
CONCERNING...{{ $concerning }}

@endif

VALID {{ $issuedAt->format('dHi') }}Z - {{ $issuedAt->copy()->addHours(2)->format('dHi') }}Z

DISCUSSION...{{ $discussionText }}

ATTN...WFO...{{ implode('...', $attnWfos) }}...

{{ $polygonLine }}
