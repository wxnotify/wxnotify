<div
    x-data="{
        publicKey: @js(config('webpush.vapid.public_key')),
        supported: ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window),
        status: 'idle',
        error: null,
        urlBase64ToUint8Array(base64String) {
            const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
            const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            const raw = atob(base64);
            return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
        },
        async enable() {
            this.error = null;
            this.status = 'working';
            try {
                const permission = await Notification.requestPermission();
                if (permission !== 'granted') {
                    throw new Error('Notifications are blocked for this site. Allow them in your browser settings and try again.');
                }
                const registration = await navigator.serviceWorker.register('/dashboard-sw.js', { scope: '/dashboard/' });
                await navigator.serviceWorker.ready;
                const existing = await registration.pushManager.getSubscription();
                const subscription = existing ?? await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: this.urlBase64ToUint8Array(this.publicKey),
                });
                await $wire.set('data.subscription', JSON.stringify(subscription.toJSON()));
                this.status = 'ready';
            } catch (e) {
                this.error = e.message || 'Could not enable browser notifications.';
                this.status = 'idle';
            }
        },
    }"
    class="space-y-3"
>
    <template x-if="!supported">
        <p class="text-sm text-danger-600 dark:text-danger-400">
            This browser does not support Web Push. On iPhone and iPad, add WxNotify to your Home Screen first, then open it from there.
        </p>
    </template>

    <template x-if="supported">
        <div class="space-y-3">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Click below and choose "Allow" when your browser asks. Alerts will then appear as system notifications on this device, even when WxNotify isn't open.
            </p>

            <x-filament::button type="button" x-on:click="enable()" x-bind:disabled="status === 'working' || status === 'ready'">
                <span x-show="status === 'idle'">Enable browser notifications</span>
                <span x-show="status === 'working'" x-cloak>Waiting for your browser…</span>
                <span x-show="status === 'ready'" x-cloak>Enabled — click Next</span>
            </x-filament::button>

            <p x-show="error" x-text="error" x-cloak class="text-sm text-danger-600 dark:text-danger-400"></p>
        </div>
    </template>
</div>
