@php
    $fact = \App\Models\DidYouKnow::active()->inRandomOrder()->first();
@endphp

@if ($fact)
    <div class="border-t border-gray-200 p-4 text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
        <p class="font-medium text-gray-700 dark:text-gray-200">Did you know?</p>
        <p class="mt-1">{{ $fact->body }}</p>
    </div>
@endif
