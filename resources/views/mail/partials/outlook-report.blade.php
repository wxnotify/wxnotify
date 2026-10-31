<div style="font-family: -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif; color: #1f2937;">
<h2 style="margin: 0 0 4px; font-size: 20px;">Severe Weather Outlook</h2>
<p style="margin: 0; font-size: 13px; color: #6b7280;">From the NOAA Storm Prediction Center convective outlooks.</p>

@foreach ($r['days'] as $day)
<h3 style="margin: 20px 0 6px; font-size: 16px;">{{ $day['heading'] }} {{ $day['circle'] }}@if ($day['available']) {{ $day['label'] }}@endif</h3>
@if (! $day['available'])
<p style="margin: 0; font-size: 14px;">{{ $day['unavailable'] }}</p>
@else
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px; border-collapse: collapse;">
<tr>
@if ($hasImage[$day['key']])
<td width="200" style="width: 200px; padding: 0 10px 0 0; vertical-align: top;"><img src="cid:spc-{{ $day['key'] }}" alt="{{ $day['alt'] }}" width="190" style="display: block; width: 190px; max-width: 100%; height: auto;"></td>
@endif
<td style="vertical-align: top;"><div>{{ $day['levels'] }}</div>@if ($day['issued'] !== null)<div style="margin-top: 4px; font-size: 12px; color: #6b7280;">{{ $day['issued'] }}</div>@endif</td>
</tr>
</table>
@if ($day['locations'] !== [])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top: 6px; font-size: 13px; border-collapse: collapse;">
@foreach ($day['locations'] as $location)
<tr style="border-bottom: 1px solid #e5e7eb;"><td style="padding: 3px 8px 3px 0; font-weight: 600;">{{ $location['label'] }}</td><td style="padding: 3px 8px 3px 0; white-space: nowrap;">{{ $location['circle'] }} {{ $location['category'] }}</td><td style="padding: 3px 0; color: #4b5563;">{{ $location['levels'] ?? '' }}</td></tr>
@endforeach
</table>
@endif
@endif
@endforeach

<h3 style="margin: 20px 0 6px; font-size: 16px;">Days 4-8</h3>
@if ($hasImage['day48'] && $r['extended_available'])
<img src="cid:spc-day48" alt="SPC Days 4-8 severe weather outlook" width="190" style="display: block; width: 190px; max-width: 100%; height: auto; margin-bottom: 6px;">
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px; border-collapse: collapse;">
@foreach ($r['extended'] as $day)
<tr style="border-bottom: 1px solid #e5e7eb;@if ($day['highlight']) background-color: #fef9c3; font-weight: 600;@endif"><td style="padding: 4px 8px 4px 4px; white-space: nowrap;">{{ $day['heading'] }}</td><td style="padding: 4px 8px 4px 0; white-space: nowrap;">{{ $day['circle'] }} {{ $day['summary'] }}</td><td style="padding: 4px 4px 4px 0;">{{ $day['inside'] ?? '' }}</td></tr>
@endforeach
</table>

@if ($r['pwo'] !== null)
<h3 style="margin: 20px 0 6px; font-size: 16px;">{{ $r['pwo']['title'] }}</h3>
@if ($r['pwo']['issued'] !== null)
<p style="margin: 0 0 6px; font-size: 12px; color: #6b7280;">{{ $r['pwo']['issued'] }}</p>
@endif
<pre style="margin: 0; padding: 8px; background-color: #f3f4f6; font-family: Menlo, Consolas, monospace; font-size: 12px; white-space: pre-wrap; word-wrap: break-word;">{{ $r['pwo']['text'] }}</pre>
@endif
</div>
