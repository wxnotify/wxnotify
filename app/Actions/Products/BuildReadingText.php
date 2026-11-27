<?php

namespace App\Actions\Products;

use App\Enums\ProductFamily;
use App\Models\IngestedProduct;
use App\Support\ReflowBulletin;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What a product shows a subscriber who reads it: `text` (as sent), `reading_text` (reflowed for a phone screen)
 * and `has_original` (whether switching between the two is worth offering). Computed per request; nothing stored
 * changes. A CAP alert never shows its XML: both texts are built from its stored description, instruction and
 * headline. A failure never reaches the caller.
 */
class BuildReadingText
{
    private const NO_TEXT = 'This alert has no text to show.';

    /** `<?xml` first, after nothing but whitespace and the sequence number, WMO header and AWIPS ID lines. */
    private const STARTS_AS_XML = '/\A(?:\xEF\xBB\xBF)?+(?:\s*+(?:[A-Z]{4}\d{2} [A-Z]{4} \d{6}(?: [A-Z]{3})?+|[A-Z0-9]{3,6})[ \t]*+(?=[\r\n]))*+\s*+<\?xml/i';

    private const XML_MARKUP = '/<\?xml|<\/?(?:[a-z]+:)?alert[\s\/>]/i';

    /** A CAP root element anywhere, prefixed or not (`<alert xmlns=...>`, `<cap:alert xmlns:cap=...>`). */
    private const CAP_ROOT = '/<(?:[a-z]+:)?alert\s+xmlns/i';

    /**
     * @return array{text: string, reading_text: string, has_original: bool}
     */
    public function __invoke(IngestedProduct $product): array
    {
        $stored = (string) $product->text;
        $isCap = $product->format === 'cap' || $this->isXml($stored);

        try {
            if ($isCap) {
                return $this->unchanged($this->capText($product));
            }

            if ($product->product?->family === ProductFamily::Aviation) {
                return $this->unchanged($stored);
            }

            $readingText = ReflowBulletin::reflow($stored);

            return [
                'text' => $stored,
                'reading_text' => $readingText,
                'has_original' => ReflowBulletin::normaliseLineEndings($stored) !== $readingText,
            ];
        } catch (Throwable $e) {
            Log::warning('Reading text failed', ['exception' => $e::class]);

            return $this->unchanged($isCap ? self::NO_TEXT : $stored);
        }
    }

    /**
     * The stored headline as the product page and the API show it: null when blank or holding XML.
     */
    public static function readableHeadline(?string $headline): ?string
    {
        return filled($headline) && ! self::containsXml($headline) ? $headline : null;
    }

    /**
     * True when a stored field holds XML markup instead of words, so it must not be shown.
     */
    private static function containsXml(string $value): bool
    {
        return preg_match(self::XML_MARKUP, $value) !== 0;
    }

    private function isXml(string $text): bool
    {
        return preg_match(self::STARTS_AS_XML, $text) === 1 || preg_match(self::CAP_ROOT, $text) === 1;
    }

    /**
     * The description (else the headline), then the instruction under its own heading.
     */
    private function capText(IngestedProduct $product): string
    {
        $body = $this->readableField($product->description) ?? $this->readableField($product->headline);
        $instruction = $this->readableField($product->instruction);

        $sections = array_filter([$body, $instruction === null ? null : "Instructions\n{$instruction}"]);

        return $sections === [] ? self::NO_TEXT : implode("\n\n", $sections);
    }

    private function readableField(?string $value): ?string
    {
        if (blank($value) || self::containsXml($value)) {
            return null;
        }

        return trim(ReflowBulletin::reflow($value));
    }

    /**
     * @return array{text: string, reading_text: string, has_original: false}
     */
    private function unchanged(string $text): array
    {
        return ['text' => $text, 'reading_text' => $text, 'has_original' => false];
    }
}
