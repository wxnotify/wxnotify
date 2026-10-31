{{-- $imageSrc: keyed day1, day2, day3, day48; the email passes cid: references, the dashboard preview SPC's own URLs, null for none. --}}
<div style="font-family: -apple-system, 'Segoe UI', Helvetica, Arial, sans-serif; color: #1f2937;">
<h2 style="margin: 0 0 4px; font-size: 20px;">Severe Weather Outlook</h2>
<p style="margin: 0; font-size: 13px; color: #6b7280;">From the <a href="{{ \App\Support\OutlookReportPresenter::SPC_URL }}" target="_blank" rel="noopener noreferrer">NOAA Storm Prediction Center</a> convective outlooks.</p>
<p style="margin: 4px 0 0; font-size: 13px; color: #6b7280;">{{ \App\Support\OutlookReportPresenter::NOTE }}</p>

@foreach ($r['days'] as $day)
<h3 style="margin: 20px 0 2px; font-size: 16px;">{{ $day['heading'] }} - {{ $day['circle'] }}@if ($day['available']) {{ $day['label'] }}@endif</h3>
@if ($day['date'] !== null)
<p style="margin: 0 0 6px; font-size: 13px; color: #6b7280;">{{ $day['date'] }}</p>
@endif
@if (! $day['available'])
<p style="margin: 0; font-size: 14px;">{{ $day['unavailable'] }}</p>
@else
@if ($imageSrc[$day['key']] !== null)
<a href="{{ $day['link'] }}" target="_blank" rel="noopener noreferrer"><img src="{{ $imageSrc[$day['key']] }}" alt="{{ $day['alt'] }}" width="380" style="display: block; width: 380px; max-width: 100%; height: auto; margin: 0 0 6px; border: 0;"></a>
@endif
<div style="font-size: 14px;">{{ $day['levels'] }}</div>
@if ($day['issued'] !== null)
<div style="margin-top: 4px; font-size: 12px; color: #6b7280;">{{ $day['issued'] }}</div>
@endif
@if ($day['locations'] !== [])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top: 6px; font-size: 13px; border-collapse: collapse;">
@foreach ($day['locations'] as $location)
<tr style="border-bottom: 1px solid #e5e7eb;"><td style="padding: 3px 8px 3px 0; font-weight: 600;">{{ $location['label'] }}</td><td style="padding: 3px 8px 3px 0; white-space: nowrap;">{{ $location['circle'] }} {{ $location['category'] }}</td><td style="padding: 3px 0; color: #4b5563;">{{ $location['levels'] ?? '' }}</td></tr>
@endforeach
</table>
@endif
@endif
@endforeach

<h3 style="margin: 20px 0 2px; font-size: 16px;">Days 4-8</h3>
@if ($r['extended_dates'] !== null)
<p style="margin: 0 0 6px; font-size: 13px; color: #6b7280;">{{ $r['extended_dates'] }}</p>
@endif
@if ($imageSrc['day48'] !== null && $r['extended_available'])
<a href="{{ $r['extended_link'] }}" target="_blank" rel="noopener noreferrer"><img src="{{ $imageSrc['day48'] }}" alt="SPC Days 4-8 severe weather outlook" width="380" style="display: block; width: 380px; max-width: 100%; height: auto; margin-bottom: 6px; border: 0;"></a>
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
