{{-- $ttaaii, $wfo (SPC/WPC's own 3-letter office, e.g. WNS), $issuedAt (Carbon), $discussionNumber,
     $productLabel (e.g. "MESOSCALE DISCUSSION"), $officeName, $areasAffected, $ugcLine,
     $discussionText, $riskLevel, $polygonLine, $attnWfos (array<string>) --}}
ACUS11 K{{ $wfo }} {{ $issuedAt->format('dHi') }}
SWOMCD
SPC MCD {{ $issuedAt->format('dHi') }}
{{ $ugcLine }}

{{ $productLabel }} {{ $discussionNumber }}
{{ $officeName }}
{{ $issuedAt->format('gi A T D M j Y') }}

AREAS AFFECTED...{{ $areasAffected }}

CONCERNING...{{ $riskLevel }}

VALID {{ $issuedAt->format('dHi') }}Z

DISCUSSION...{{ $discussionText }}

ATTN...WFO...{{ implode('...', $attnWfos) }}...

{{ $polygonLine }}
