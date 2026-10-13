<div
    x-data="{
        atBottom: false,
        update() {
            this.atBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 100
        },
    }"
    x-init="update()"
    x-on:scroll.window.passive="update()"
    x-on:resize.window.passive="update()"
    x-on:livewire:navigated.window="update()"
    x-show="! atBottom"
    x-transition.opacity
    x-cloak
    class="pointer-events-none fixed bottom-24 end-6 z-30"
>
    <button
        type="button"
        title="Scroll to bottom"
        aria-label="Scroll to bottom"
        x-on:click="window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'smooth' })"
        class="pointer-events-auto flex size-10 items-center justify-center rounded-full border border-gray-300 bg-white/40 text-gray-700 shadow-sm backdrop-blur transition hover:bg-white/70 dark:border-white/20 dark:bg-gray-900/40 dark:text-gray-200 dark:hover:bg-gray-900/70"
    >
        <x-filament::icon icon="heroicon-o-chevron-down" class="size-5" />
    </button>
</div>
