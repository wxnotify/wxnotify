@props(['counts'])
@php
    $max = max(1, max($counts));
    $step = 120 / (count($counts) - 1);
    $points = collect($counts)
        ->map(fn ($count, $i) => round($i * $step, 1).','.round(26 - ($count / $max) * 24, 1))
        ->implode(' ');
@endphp
<svg viewBox="0 0 120 28" preserveAspectRatio="none" role="img" aria-label="Products received per hour over the last 24 hours" style="width:100%;height:28px;color:rgb(var(--primary-500, 14 107 116))">
    <polyline points="{{ $points }}" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" />
</svg>
