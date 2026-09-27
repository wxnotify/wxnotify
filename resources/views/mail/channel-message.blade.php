<x-mail::message>
<pre style="white-space: pre-wrap; word-wrap: break-word; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 13px; line-height: 1.5; margin: 0;">{{ $messageBody }}</pre>
@isset($viewUrl)
<p style="margin-top: 16px;"><a href="{{ $viewUrl }}">View this product online</a></p>
@endisset
</x-mail::message>
