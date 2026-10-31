{{--
    Sits inside the form's collapsible "Today's Report" section and reads its Alpine `isCollapsed`.
    It calls load() only while the section is open: the first time it opens, and again when the
    locations ticked in the form ($wire.$parent.data.location_ids) differ from the ones last loaded.
--}}
<div
    x-data="{
        loadedFor: null,
        ticked() {
            const ids = $wire.$parent?.data?.location_ids ?? []

            return JSON.stringify(Object.values(ids).map(String).sort())
        },
        refresh() {
            if (this.isCollapsed === true) {
                return
            }

            const ids = this.ticked()

            if (ids === this.loadedFor) {
                return
            }

            this.loadedFor = ids
            $wire.load(JSON.parse(ids))
        },
    }"
    x-init="$watch('isCollapsed', () => refresh()); $watch('ticked()', () => refresh()); refresh()"
>
    @if ($status === 'idle')
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ "Loading today's report…" }}</p>
    @elseif ($status === 'paid_only')
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $message }}</p>
    @elseif ($status === 'unavailable')
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ "SPC is unavailable right now, so today's report cannot be shown. Please try again in a few minutes." }}</p>
    @else
        <p wire:loading class="mb-2 text-sm text-gray-600 dark:text-gray-300">Updating…</p>
        <div class="overflow-x-auto rounded-lg bg-white p-4 ring-1 ring-gray-950/5" wire:loading.class="opacity-50">
            @include('mail.partials.outlook-report', ['r' => $r, 'imageSrc' => $imageSrc])
        </div>
    @endif
</div>
