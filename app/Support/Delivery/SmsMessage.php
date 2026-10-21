<?php

namespace App\Support\Delivery;

use Illuminate\Support\Str;

/**
 * The text an SMS channel sends: GSM-7 basic characters only and at most two concatenated
 * segments, so one emoji or curly quote never turns the message into UCS-2 (70 characters a
 * segment) and a long bulletin never becomes a run of texts.
 *
 * An alert is `{title}\n{summary}\n{link}`: the title (already prefixed by MessagePrefix) cut
 * to 100 characters; the link, `meta.url` (the View Product page), never cut and dropped only
 * when longer than 120 characters; the summary, the body on one line, filling what is left and
 * cut at a word with "...". A confirmation and a test send fixed texts instead of the body.
 *
 * Never uses `Str::limit(..., preserveWords: true)` (see jobs.md): cutting is done here.
 */
class SmsMessage
{
    /**
     * Two concatenated GSM-7 segments of 153 characters each.
     */
    public const MAX_LENGTH = 306;

    public const MAX_TITLE_LENGTH = 100;

    public const MAX_LINK_LENGTH = 120;

    private const ELLIPSIS = '...';

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function compose(string $body, ?string $title, array $meta, ?string $optOutHint = null): string
    {
        return match ($meta['type'] ?? 'alert') {
            'confirmation' => self::confirmation((string) ($meta['code'] ?? ''), $optOutHint),
            'test' => self::test($body),
            default => self::alert($body, $title, $meta['url'] ?? null),
        };
    }

    /**
     * Folds text to one line of GSM-7 basic characters: ASCII transliteration, brackets to
     * parentheses, the extension characters and the backtick (not in GSM-7) handled, anything
     * else unprintable dropped, and whitespace collapsed to single spaces.
     */
    public static function fold(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $text = Str::ascii($text);
        $text = strtr($text, ['[' => '(', ']' => ')', '`' => "'"]);
        $text = preg_replace('/[^\x20-\x7E]|[{}~^\\\\|]/', '', $text) ?? '';

        return trim(preg_replace('/ {2,}/', ' ', $text) ?? '');
    }

    private static function confirmation(string $code, ?string $optOutHint): string
    {
        $text = self::fold("WxNotify code: {$code}. Enter it in your dashboard to confirm this number for weather alerts. ".($optOutHint ?? ''));

        return self::cut($text, self::MAX_LENGTH);
    }

    /**
     * The test body ends with the channel's label in double quotes; only that label is sent.
     */
    private static function test(string $body): string
    {
        $label = preg_match('/"(.*)"/s', $body, $matches) === 1 ? self::fold($matches[1]) : '';
        $label = str_replace('"', "'", $label);

        if ($label === '') {
            return 'WxNotify test: this channel is working.';
        }

        $frame = strlen('WxNotify test: "" is working.');

        return 'WxNotify test: "'.self::cut($label, self::MAX_LENGTH - $frame).'" is working.';
    }

    private static function alert(string $body, ?string $title, mixed $url): string
    {
        $title = self::cut(self::fold((string) $title), self::MAX_TITLE_LENGTH);
        $link = self::link($url);

        $fixed = array_values(array_filter([$title, $link], fn (string $part): bool => $part !== ''));
        $used = array_sum(array_map(strlen(...), $fixed)) + count($fixed);
        $summary = self::cut(self::fold($body), self::MAX_LENGTH - $used);

        return implode("\n", array_values(array_filter(
            [$title, $summary, $link],
            fn (string $part): bool => $part !== '',
        )));
    }

    /**
     * The link as given, or nothing: it is never cut, and one too long or holding a character
     * that folding would change (so a cut or altered address is never sent) is dropped.
     */
    private static function link(mixed $url): string
    {
        if (! is_string($url) || $url === '' || strlen($url) > self::MAX_LINK_LENGTH) {
            return '';
        }

        return self::fold($url) === $url && ! str_contains($url, ' ') ? $url : '';
    }

    /**
     * Cuts a folded line to at most $max characters at a word boundary, ending in "...". A
     * single word longer than the room is cut mid-word; no room for more than the ellipsis
     * leaves nothing.
     */
    private static function cut(string $text, int $max): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }

        $room = $max - strlen(self::ELLIPSIS);

        if ($room < 1) {
            return '';
        }

        $kept = substr($text, 0, $room);

        if ($text[$room] !== ' ') {
            $lastSpace = strrpos($kept, ' ');

            if ($lastSpace !== false && $lastSpace > 0) {
                $kept = substr($kept, 0, $lastSpace);
            }
        }

        $kept = rtrim($kept);

        return $kept === '' ? '' : $kept.self::ELLIPSIS;
    }
}
