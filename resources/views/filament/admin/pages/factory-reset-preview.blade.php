<div class="rounded-lg border p-4 text-sm" style="border-color: #dc2626;">
    <p style="font-weight: 600; margin-bottom: 0.5rem;">This will permanently delete:</p>
    <ul style="list-style: disc; padding-left: 1.25rem;">
        @foreach ($lines as $line)
            <li>{{ $line }}</li>
        @endforeach
    </ul>
</div>
