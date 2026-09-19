<div x-data="{ copied: false }" class="space-y-2">
    <div class="flex justify-end">
        <button
            type="button"
            class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
            x-on:click="navigator.clipboard.writeText($refs.previewText.innerText).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
        >
            <span x-show="!copied">Copy text</span>
            <span x-show="copied" x-cloak>Copied</span>
        </button>
    </div>
    <div x-ref="previewText" class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900" style="white-space: pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.75rem;">{{ $text }}</div>
</div>
