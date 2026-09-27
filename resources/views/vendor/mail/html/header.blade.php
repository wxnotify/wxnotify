@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<img src="{{ rtrim((string) config('app.url'), '/').'/images/logo.png' }}" alt="" width="38" height="40" style="height: 40px; width: auto; vertical-align: middle; border: 0;">
<span style="display: inline-block; vertical-align: middle; margin-left: 10px; font-size: 19px; font-weight: bold; color: #18181b;">{{ config('app.name') }}</span>
</a>
</td>
</tr>
