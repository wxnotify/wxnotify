<?php

namespace App\Support\Delivery;

/**
 * The text a messaging channel sends.
 *
 * Chat types (Google Chat, Mattermost, Rocket.Chat, Matrix): an alert is
 * `{title}\n\n{body}\n\n{link}`, any part left out when empty. The title is already prefixed
 * (MessagePrefix); the link is `meta.url` (the View Product page) and is never cut (it is
 * dropped only when it holds whitespace or would not fit at all); the body keeps its line
 * breaks and Unicode and is cut at a word with "..." so the whole message fits the type's
 * `maxLength()`. A confirmation and a test send the body they are given, unchanged.
 *
 * WhatsApp: one line, `{title}: {summary} {link}`, at most WHATSAPP_MAX_LENGTH characters. Meta
 * refuses a template parameter holding a newline, a tab or more than four spaces in a row, so
 * every run of whitespace or control characters becomes one space. A confirmation and a test
 * send fixed texts.
 *
 * Plain text, never markdown we write ourselves. Checked against Apprise 2.0.0 with
 * `requests.post` monkeypatched (no network): through the Apprise API with no `format` in the
 * request and APPRISE_DEFAULT_FORMAT unset (WxNotify's case), the body reaches `gchat://`,
 * `mmosts://` and `rockets://` byte for byte, so `*bold*`, `_x_`, `<b>`, `&` and NWS `* WHAT...`
 * bullets are left to the target's own rendering. With an input format of `text`, Apprise
 * instead escapes for the markdown targets: Google Chat got `\*bold\*`, `\_x\_`, `<b&gt;` and
 * `&amp;`, and Rocket.Chat also `\.` and `\-`, both inside the View Product link, which would
 * break it. So the request must keep sending no `format`, and APPRISE_DEFAULT_FORMAT must stay
 * unset on the Apprise container.
 *
 * Lengths are counted in characters (`mb_*`), never bytes. Never uses
 * `Str::limit(..., preserveWords: true)` (see jobs.md): cutting is done here.
 */
class MessagingMessage
{
    /**
     * Meta's template parameter limit is 1024 characters; this leaves room for the template's
     * own words around `{{1}}`.
     */
    public const WHATSAPP_MAX_LENGTH = 900;

    private const ELLIPSIS = '...';

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function chat(string $body, ?string $title, array $meta, int $maxLength): string
    {
        if (in_array($meta['type'] ?? 'alert', ['confirmation', 'test'], true)) {
            return $body;
        }

        $title = trim((string) $title);
        $link = self::link($meta['url'] ?? null);
        $body = trim($body);

        if ($link !== '' && mb_strlen($link) > $maxLength) {
            $link = '';
        }

        $fixed = array_values(array_filter([$title, $link], fn (string $part): bool => $part !== ''));
        $separators = 2 * count($fixed);
        $room = $maxLength - array_sum(array_map(mb_strlen(...), $fixed)) - $separators;

        if ($room < 0) {
            $title = self::cut($title, max(0, mb_strlen($title) + $room));
            $room = 0;
        }

        $summary = $room > 0 ? self::cut($body, $room) : '';

        return implode("\n\n", array_values(array_filter(
            [$title, $summary, $link],
            fn (string $part): bool => $part !== '',
        )));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function whatsapp(string $body, ?string $title, array $meta): string
    {
        return match ($meta['type'] ?? 'alert') {
            'confirmation' => 'Your WxNotify code is '.self::oneLine((string) ($meta['code'] ?? '')).'. Enter it in your dashboard to confirm this number for weather alerts.',
            'test' => self::whatsappTest($body),
            default => self::whatsappAlert($body, $title, $meta['url'] ?? null),
        };
    }

    /**
     * Folds text to one line: every run of whitespace, separators or control characters (newline,
     * tab, no-break and ideographic spaces, line separators) becomes a single space.
     */
    public static function oneLine(string $text): string
    {
        return trim(preg_replace('/[\s\p{Z}\p{Cc}]+/u', ' ', $text) ?? '');
    }

    private static function whatsappAlert(string $body, ?string $title, mixed $url): string
    {
        $link = self::link($url);
        $title = self::oneLine((string) $title);
        $summary = self::oneLine($body);

        if (mb_strlen($link) > self::WHATSAPP_MAX_LENGTH) {
            $link = '';
        }

        $linkRoom = $link === '' ? 0 : mb_strlen($link) + 1;
        $title = self::cut($title, max(0, self::WHATSAPP_MAX_LENGTH - $linkRoom - 2));
        $prefix = $title === '' ? '' : $title.': ';
        $summary = self::cut($summary, self::WHATSAPP_MAX_LENGTH - $linkRoom - mb_strlen($prefix));

        if ($summary === '') {
            $prefix = $title;
        }

        return trim(implode(' ', array_filter([$prefix.$summary, $link], fn (string $part): bool => $part !== '')));
    }

    /**
     * The test body ends with the channel's label in double quotes; only that label is sent.
     */
    private static function whatsappTest(string $body): string
    {
        $label = preg_match('/"(.*)"/su', $body, $matches) === 1 ? self::oneLine($matches[1]) : '';

        if ($label === '') {
            return 'WxNotify test: this channel is working.';
        }

        $frame = mb_strlen('WxNotify test: "" is working.');

        return 'WxNotify test: "'.self::cut($label, self::WHATSAPP_MAX_LENGTH - $frame).'" is working.';
    }

    /**
     * The link as given, or nothing when it is not a string or holds whitespace (a link is never
     * altered).
     */
    private static function link(mixed $url): string
    {
        if (! is_string($url) || $url === '' || preg_match('/[\s\p{Z}\p{Cc}]/u', $url) === 1) {
            return '';
        }

        return $url;
    }

    /**
     * Cuts text to at most $max characters at a word boundary, ending in "...". A single word
     * longer than the room is cut mid-word; no room for more than the ellipsis leaves nothing.
     */
    private static function cut(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $room = $max - mb_strlen(self::ELLIPSIS);

        if ($room < 1) {
            return '';
        }

        $kept = mb_substr($text, 0, $room);

        if (preg_match('/^\s/u', mb_substr($text, $room, 1)) !== 1) {
            $lastSpace = self::lastWhitespace($kept);

            if ($lastSpace !== null && $lastSpace > 0) {
                $kept = mb_substr($kept, 0, $lastSpace);
            }
        }

        $kept = rtrim($kept);

        return $kept === '' ? '' : $kept.self::ELLIPSIS;
    }

    /**
     * The character offset of the last whitespace in the text, or null.
     */
    private static function lastWhitespace(string $text): ?int
    {
        if (preg_match_all('/\s/u', $text, $matches, PREG_OFFSET_CAPTURE) < 1) {
            return null;
        }

        $byteOffset = end($matches[0])[1];

        return mb_strlen(substr($text, 0, $byteOffset));
    }
}
