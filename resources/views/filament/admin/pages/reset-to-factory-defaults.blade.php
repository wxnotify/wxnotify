<x-filament-panels::page>
    <form wire:submit.prevent class="space-y-6">
        {{ $this->form }}
    </form>

    <div class="flex gap-3">
        @if (! $this->isReauthenticated())
            {{ $this->reauthAction }}
        @else
            {{ $this->resetAction }}
        @endif
    </div>
</x-filament-panels::page>
