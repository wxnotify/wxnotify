@php
    $fact = \App\Models\DidYouKnow::active()->inRandomOrder()->first();
@endphp

@if ($fact)
    <div class="border-t border-gray-200 p-4 text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
        <p class="flex items-center gap-2 font-medium text-gray-700 dark:text-gray-200">
            <x-filament::icon icon="heroicon-s-information-circle" class="h-4 w-4 shrink-0" />
            &nbsp; Did you know?
        </p>
        <p class="mt-1">{{ $fact->body }}</p>
    </div>
@endif
