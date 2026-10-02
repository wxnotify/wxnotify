<div style="font-family: -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif; color: #1f2937;">
<h2 style="margin: 0 0 4px; font-size: 20px;">{{ $s['location'] }}</h2>
@if ($s['station_line'] !== null)
<p style="margin: 0; font-size: 13px; color: #6b7280;">{{ $s['station_line'] }}</p>
@endif
@if ($s['sun_line'] !== null)
<p style="margin: 2px 0 0; font-size: 13px; color: #6b7280;">{{ $s['sun_line'] }}</p>
@endif

<h3 style="margin: 20px 0 6px; font-size: 16px;">Alerts in Effect</h3>
@if ($s['alerts'] === null)
<p style="margin: 0; font-size: 14px;">Alert information is unavailable right now.</p>
@elseif ($s['alerts'] === [])
<p style="margin: 0; font-size: 14px;">No active alerts.</p>
@else
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px; border-collapse: collapse;">
@foreach ($s['alerts'] as $alert)
<tr><td style="padding: 6px 0; border-bottom: 1px solid #e5e7eb;"><strong>{{ $alert['event'] }}</strong>@if ($alert['until'] !== null)<span style="color: #6b7280;">&nbsp;· {{ $alert['until'] }}</span>@endif @if ($alert['headline'] !== null)<br><span style="color: #4b5563;">{{ $alert['headline'] }}</span>@endif</td></tr>
@endforeach
</table>
@endif

@if ($s['current'] !== null)
<h3 style="margin: 20px 0 6px; font-size: 16px;">Current Conditions</h3>
<table role="presentation" cellpadding="0" cellspacing="0" style="font-size: 14px; border-collapse: collapse;">
<tr>
<td style="padding: 0 12px 0 0; vertical-align: top;"><img src="{{ $s['current']['icon_url'] }}" alt="{{ $s['current']['description'] ?? 'Current conditions' }}" width="50" height="50" style="display: block;"></td>
<td style="vertical-align: top;">@if ($s['current']['description'] !== null)<div style="font-size: 16px; font-weight: 600;">{{ $s['current']['description'] }}</div>@endif
<table role="presentation" cellpadding="0" cellspacing="0" style="font-size: 14px;">
@foreach ($s['current']['rows'] as [$label, $value])
<tr><td style="padding: 1px 12px 1px 0; color: #6b7280;">{{ $label }}</td><td style="padding: 1px 0;">{{ $value }}</td></tr>
@endforeach
</table>
<div style="margin-top: 4px; font-size: 12px; color: #6b7280;">{{ $s['current']['updated'] }}</div></td>
</tr>
</table>
@endif

@if ($hasRadar && $s['radar_alt'] !== null)
<h3 style="margin: 20px 0 6px; font-size: 16px;">Radar</h3>
<img src="cid:radar-snapshot" alt="{{ $s['radar_alt'] }}" width="570" style="max-width: 100%; height: auto; display: block;">
<div style="margin-top: 4px; font-size: 12px; color: #6b7280;">{{ $s['radar_caption'] }}</div>
@endif

@if ($s['hourly'] !== null)
<h3 style="margin: 20px 0 6px; font-size: 16px;">Next 12 Hours</h3>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size: 13px; border-collapse: collapse;">
@foreach ($s['hourly'] as $hour)
<tr style="border-bottom: 1px solid #e5e7eb;"><td style="padding: 4px 8px 4px 0; white-space: nowrap;">{{ $hour['time'] }}</td><td style="padding: 4px 8px 4px 0;"><img src="{{ $hour['icon_url'] }}" alt="{{ $hour['summary'] }}" width="28" height="28" style="display: block;"></td><td style="padding: 4px 8px 4px 0; white-space: nowrap;">{{ $hour['temp'] }}</td><td style="padding: 4px 8px 4px 0; white-space: nowrap;">{{ $hour['precip'] }}</td><td style="padding: 4px 8px 4px 0; white-space: nowrap;">{{ $hour['wind'] }}</td><td style="padding: 4px 0;">{{ $hour['summary'] }}</td></tr>
@endforeach
</table>
@endif

<h3 style="margin: 20px 0 6px; font-size: 16px;">7-Day Forecast</h3>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size: 14px; border-collapse: collapse;">
@foreach ($s['periods'] as $period)
<tr style="border-bottom: 1px solid #e5e7eb;"><td style="padding: 6px 10px 6px 0; vertical-align: top;"><img src="{{ $period['icon_url'] }}" alt="{{ $period['summary'] }}" width="40" height="40" style="display: block;"></td><td style="padding: 6px 10px 6px 0; vertical-align: top;"><strong>{{ $period['name'] }}</strong>@if ($period['date'] !== null)<br><span style="color: #6b7280; font-size: 12px;">{{ $period['date'] }}</span>@endif</td><td style="padding: 6px 10px 6px 0; vertical-align: top;">{{ $period['summary'] }}</td><td style="padding: 6px 0; vertical-align: top; white-space: nowrap; font-weight: 600;">{{ $period['temp'] }}</td></tr>
@endforeach
</table>
</div>
