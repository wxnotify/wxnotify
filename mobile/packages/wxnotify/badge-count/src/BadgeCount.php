<?php

namespace Wxnotify\BadgeCount;

class BadgeCount
{
    /**
     * Sets the app icon badge number. iOS shows it on the icon; Android has no badge number, so its
     * native side only answers success. Returns the bridge's data, or null off a device.
     */
    public function set(int $count): ?object
    {
        if (! function_exists('nativephp_call')) {
            return null;
        }

        $result = nativephp_call('BadgeCount.Set', json_encode(['count' => $count]));

        if (! $result) {
            return null;
        }

        $decoded = json_decode($result);

        return is_object($decoded) ? ($decoded->data ?? null) : null;
    }

    /**
     * The phone's current IANA timezone id (for example America/Chicago), or null off a device or
     * when the bridge gives no usable answer. Never throws.
     */
    public function timezone(): ?string
    {
        if (! function_exists('nativephp_call')) {
            return null;
        }

        try {
            $result = nativephp_call('BadgeCount.Timezone', '{}');

            if (! $result) {
                return null;
            }

            $decoded = json_decode($result);
            $timezone = is_object($decoded) ? ($decoded->data->timezone ?? null) : null;

            return is_string($timezone) && $timezone !== '' ? $timezone : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
