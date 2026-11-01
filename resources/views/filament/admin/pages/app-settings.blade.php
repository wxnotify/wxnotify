<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        {{-- Inline style: the admin panel has no custom theme, so app utility classes such as mt-6 are not compiled. --}}
        <div style="margin-top: 1.5rem;">
            <x-filament::button type="submit">Save settings</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
