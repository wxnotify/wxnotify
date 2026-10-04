<?php

namespace App\Support;

/**
 * An opaque pagination cursor for the app's alert feed: the sort time and id of the last
 * entry on the previous page, base64url-encoded JSON.
 */
final class AppAlertCursor
{
    public static function encode(string $at, int $id): string
    {
        return rtrim(strtr(base64_encode(json_encode(['at' => $at, 'id' => $id])), '+/', '-_'), '=');
    }

    /**
     * @return array{at: string, id: int}|null null when the cursor cannot be read
     */
    public static function decode(string $cursor): ?array
    {
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);

        if (! is_array($data) || ! is_string($data['at'] ?? null) || ! is_int($data['id'] ?? null) || strtotime($data['at']) === false) {
            return null;
        }

        return ['at' => $data['at'], 'id' => $data['id']];
    }
}
