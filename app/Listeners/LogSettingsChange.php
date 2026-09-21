<?php

namespace App\Listeners;

use Spatie\LaravelSettings\Events\SavingSettings;

/**
 * Audit-logs changed settings properties.
 *
 * Registered by Laravel's event discovery; do NOT also register it in
 * AppServiceProvider, or every change is logged twice.
 *
 * Encrypted properties (secrets) are logged as `[set]` / `[not set]` markers
 * only, so no secret value ever reaches the activity log.
 *
 * The `!==` comparison assumes scalar settings (bool/int/?string); a future
 * array or object property would need a different comparison.
 */
class LogSettingsChange
{
    public function handle(SavingSettings $event): void
    {
        $group = $event->settings::group();
        $config = $event->settings->settingsConfig();
        $changes = [];

        foreach ($event->properties as $name => $new) {
            $old = $event->originalValues?->get($name);

            if ($old === $new) {
                continue;
            }

            $changes[$name] = $config->isEncrypted($name)
                ? ['old' => $this->marker($old), 'new' => $this->marker($new)]
                : ['old' => $old, 'new' => $new];
        }

        if ($changes === []) {
            return;
        }

        activity('settings')
            ->withProperties(['group' => $group, 'changes' => $changes])
            ->log("Updated {$group} settings");
    }

    private function marker(mixed $value): string
    {
        return filled($value) ? '[set]' : '[not set]';
    }
}
