<?php

namespace App\Support\Delivery;

use App\Support\MessagePrefix;
use Illuminate\Support\Str;

/**
 * The TwiML a Twilio voice call reads out: the same line said twice, a one-second pause
 * between, the second time after "Again.":
 * `<Response><Say language="en-US">…</Say><Pause length="1"/><Say language="en-US">Again. …</Say></Response>`.
 *
 * An alert is "WxNotify weather alert. {title}. {first sentence of the summary}." with the title
 * cut to 150 characters and the sentence to 200, at a word. The admin message prefix ChannelDispatcher puts
 * in front of the title is dropped (MessagePrefix::strip()), so it is not read out after "WxNotify
 * weather alert"; the first sentence does not end at a common abbreviation ("St.", "U.S."). A confirmation reads the code digit
 * by digit ("1. 2. 3."), so each digit gets its own pause; a test call says a fixed sentence.
 *
 * Every dynamic string is folded to printable ASCII first (which also removes every character
 * XML 1.0 forbids), web addresses are dropped (a link cannot be heard), and the result is escaped
 * with `htmlspecialchars(..., ENT_XML1 | ENT_QUOTES)`, so a title holding `</Say><Redirect>` is
 * read out as text and never becomes a verb. Twilio limits the `Twiml` parameter to 4000
 * characters; the script is kept under that by dropping whole words from the end of the line
 * until it fits, which is deterministic for the same input.
 *
 * Never uses `Str::limit(..., preserveWords: true)` (see jobs.md): cutting is done here.
 */
class VoiceScript
{
    /**
     * Twilio's limit for the `Twiml` parameter of a Calls request; the script is always shorter.
     */
    public const MAX_LENGTH = 4000;

    public const MAX_TITLE_LENGTH = 150;

    public const MAX_SUMMARY_LENGTH = 200;

    /**
     * Words that end in a full stop inside a sentence, lower case and without that full stop.
     */
    private const ABBREVIATIONS = ['st', 'ste', 'mt', 'ft', 'dr', 'no', 'rd', 'ave', 'hwy', 'jct', 'u.s'];

    /**
     * Longest code read out; a confirmation code is six digits.
     */
    private const MAX_CODE_DIGITS = 12;

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function compose(string $body, ?string $title, array $meta): string
    {
        $line = match ($meta['type'] ?? 'alert') {
            'confirmation' => self::confirmation((string) ($meta['code'] ?? '')),
            'test' => 'This is a WxNotify test call. Your voice channel is working.',
            default => self::alert($body, $title),
        };

        $script = self::script($line);

        while (strlen($script) >= self::MAX_LENGTH) {
            $line = self::dropLastWord($line);
            $script = self::script($line);
        }

        return $script;
    }

    /**
     * Folds text to one line of printable ASCII: invalid UTF-8 repaired, ASCII transliteration,
     * every other character (controls included) dropped, whitespace collapsed.
     */
    public static function fold(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        $text = Str::ascii($text);
        $text = preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';

        return trim(preg_replace('/ {2,}/', ' ', $text) ?? '');
    }

    private static function script(string $line): string
    {
        $say = htmlspecialchars($line, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<Response><Say language="en-US">'.$say.'</Say><Pause length="1"/>'
            .'<Say language="en-US">Again. '.$say.'</Say></Response>';
    }

    private static function confirmation(string $code): string
    {
        $digits = str_split(substr(preg_replace('/\D/', '', $code) ?? '', 0, self::MAX_CODE_DIGITS));

        if ($digits === [] || $digits === ['']) {
            return 'This is a WxNotify confirmation call.';
        }

        return 'Your WxNotify confirmation code is '.implode('. ', $digits).'.';
    }

    private static function alert(string $body, ?string $title): string
    {
        $parts = array_filter([
            'WxNotify weather alert',
            self::cut(self::speakable(MessagePrefix::strip((string) $title)), self::MAX_TITLE_LENGTH),
            self::cut(self::firstSentence(self::speakable($body)), self::MAX_SUMMARY_LENGTH),
        ], fn (string $part): bool => $part !== '');

        return implode('. ', $parts).'.';
    }

    /**
     * Folded text with web addresses removed and NWS-style "..." separators read as commas.
     */
    private static function speakable(string $text): string
    {
        $text = self::fold($text);
        $text = preg_replace('~(?:https?://|www\.)\S*~i', '', $text) ?? '';
        $text = preg_replace('/\.{2,}/', ', ', $text) ?? '';
        $text = preg_replace('/\s+([,.!?;:])/', '$1', $text) ?? '';
        $text = preg_replace('/,(\s*,)+/', ',', $text) ?? '';
        $text = preg_replace('/ {2,}/', ' ', $text) ?? '';

        return self::trimPunctuation($text);
    }

    /**
     * The text up to the first `.`, `!` or `?` followed by a space or the end, skipping a full stop
     * that ends one of the ABBREVIATIONS ("near St. Louis" is not a sentence end).
     */
    private static function firstSentence(string $text): string
    {
        preg_match_all('/[.!?](?=\s|$)/', $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$mark, $offset]) {
            $before = substr($text, 0, $offset);

            if ($mark === '.' && self::endsWithAbbreviation($before)) {
                continue;
            }

            return self::trimPunctuation($before);
        }

        return $text;
    }

    private static function endsWithAbbreviation(string $text): bool
    {
        $lastWord = strrchr(' '.$text, ' ');
        $lastWord = ltrim(substr((string) $lastWord, 1), '("\'');

        return in_array(strtolower($lastWord), self::ABBREVIATIONS, true);
    }

    /**
     * Cuts a line to at most $max characters, at the last space when the cut falls inside a
     * word (a single word longer than $max is cut mid-word).
     */
    private static function cut(string $text, int $max): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }

        $kept = substr($text, 0, $max);

        if ($text[$max] !== ' ') {
            $lastSpace = strrpos($kept, ' ');

            if ($lastSpace !== false && $lastSpace > 0) {
                $kept = substr($kept, 0, $lastSpace);
            }
        }

        return self::trimPunctuation($kept);
    }

    /**
     * The spoken line without its last word, still ending in a full stop. A line of one word
     * loses half its characters instead, so the loop in compose() always ends.
     */
    private static function dropLastWord(string $line): string
    {
        $line = self::trimPunctuation($line);
        $lastSpace = strrpos($line, ' ');
        $line = $lastSpace !== false ? substr($line, 0, $lastSpace) : substr($line, 0, intdiv(strlen($line), 2));
        $line = self::trimPunctuation($line);

        return $line === '' ? '' : $line.'.';
    }

    private static function trimPunctuation(string $text): string
    {
        return trim($text, " .,;:!?-\t");
    }
}
