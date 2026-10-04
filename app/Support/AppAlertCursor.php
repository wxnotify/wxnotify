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

        if (! is_array($data) || ! is_string($data['at'] ?? null) || ! is_int($data['id'] ?? null)) {
            return null;
        }

        // dispatched_at is timestamp(0), so real cursors are always exactly Y-m-d H:i:s. Anything
        // looser (strtotime accepts "+1 week" and "2026-02-30") would reach Postgres and fail there.
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $data['at'], new \DateTimeZone('UTC'));

        if ($at === false || $at->format('Y-m-d H:i:s') !== $data['at']) {
            return null;
        }

        return ['at' => $data['at'], 'id' => $data['id']];
    }
}
