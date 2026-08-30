<x-filament-panels::page>
    <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-white/10 dark:bg-gray-900">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Start, stop, and check the connection status of the feed clients from here — not built yet.
            This screen needs a TLS-client-certificate-authenticated connection to the pipeline server's
            Docker Engine API, which doesn't exist from this app today. The real NWWS and EMWIN clients
            run as their own, intentionally separate project (<code>nwws-go-client/</code>).
        </p>
    </div>

    <div class="mt-6 space-y-3">
        @foreach (['NWWS', 'EMWIN Byteblaster', 'EMWIN HTTP backup'] as $client)
            <div class="flex items-center justify-between rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                <span class="font-medium text-gray-700 dark:text-gray-200">{{ $client }}</span>
                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    Status unknown
                </span>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
