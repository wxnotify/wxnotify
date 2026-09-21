<?php

namespace App\Listeners;

use Spatie\LaravelSettings\Events\SavingSettings;

class LogSettingsChange
{
    public function handle(SavingSettings $event): void
    {
        $group = $event->settings::group();
        $changes = [];

        foreach ($event->properties as $name => $new) {
            $old = $event->originalValues?->get($name);

            if ($old !== $new) {
                $changes[$name] = ['old' => $old, 'new' => $new];
            }
        }

        if ($changes === []) {
            return;
        }

        activity('settings')
            ->withProperties(['group' => $group, 'changes' => $changes])
            ->log("Updated {$group} settings");
    }
}
