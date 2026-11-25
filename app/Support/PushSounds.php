<?php

namespace App\Support;

use App\Enums\PushSoundKind;

/**
 * The push notification sounds a subscriber can pick, from config('wxnotify.push_sounds').
 */
class PushSounds
{
    /**
     * @return array<string, string> slug => label
     */
    public static function all(): array
    {
        return config('wxnotify.push_sounds');
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::all());
    }

    public static function isKnown(?string $slug): bool
    {
        return $slug !== null && array_key_exists($slug, self::all());
    }

    /**
     * @return array{kinds: list<array{value: string, label: string, description: string}>, sounds: list<array{value: string, label: string}>}
     */
    public static function options(): array
    {
        $kinds = collect(PushSoundKind::cases())
            ->sortBy(fn (PushSoundKind $kind): int => $kind->priority())
            ->map(fn (PushSoundKind $kind): array => [
                'value' => $kind->value,
                'label' => $kind->label(),
                'description' => $kind->description(),
            ])
            ->values()
            ->all();

        $sounds = [];
        foreach (self::all() as $slug => $label) {
            $sounds[] = ['value' => $slug, 'label' => $label];
        }

        return ['kinds' => $kinds, 'sounds' => $sounds];
    }
}
