<div>
    @if ($error)
        <p style="font-size:0.875rem;opacity:0.7;margin:0;">{{ $error }}</p>
    @elseif ($lines === [])
        <p style="font-size:0.875rem;opacity:0.7;margin:0;">No log output.</p>
    @else
        <pre style="max-height:70vh;overflow:auto;white-space:pre-wrap;word-break:break-word;border-radius:0.5rem;background:#0b0f19;color:#f3f4f6;padding:0.75rem;font-size:0.75rem;margin:0;">@foreach ($lines as $line){{ $line }}
@endforeach</pre>
    @endif
</div>
