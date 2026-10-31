<?php

namespace App\Mail;

use App\Support\OutlookReportPresenter;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\HtmlString;
use Symfony\Component\Mime\Email;

/**
 * The Full Severe Weather Outlook Report: Days 1-3 with their SPC graphic, category, levels and one
 * row per chosen location, the Days 4-8 list, and the PWO when the payload carries one. Rendered in
 * the shared Markdown mail layout like DailySummaryMail, with the section HTML collapsed to one line
 * so CommonMark leaves it alone.
 *
 * The graphics are Symfony inline embeds referenced as cid:spc-day1 .. cid:spc-day48, each linking to
 * its SPC outlook page. Like
 * DailySummaryMail this must be sent, never queued: the constructor registers a closure.
 */
class OutlookReportMail extends Mailable
{
    private const IMAGE_KEYS = ['day1', 'day2', 'day3', 'day48'];

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, array{bytes: string, mime: string}|null>  $images  keyed day1, day2, day3, day48; a missing or null entry is left out
     */
    public function __construct(
        public readonly ?string $messageTitle,
        public readonly array $report,
        public readonly string $briefText,
        public readonly array $images,
    ) {
        foreach ($this->embeddable() as $key => $image) {
            $this->withSymfonyMessage(fn (Email $message) => $message->embed($image['bytes'], "spc-{$key}", $image['mime']));
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: filled($this->messageTitle) ? $this->messageTitle : (string) config('app.name'),
        );
    }

    public function content(): Content
    {
        $view = OutlookReportPresenter::present($this->report);
        $imageSrc = array_map(fn (string $key): ?string => isset($this->embeddable()[$key]) ? "cid:spc-{$key}" : null, array_combine(self::IMAGE_KEYS, self::IMAGE_KEYS));

        return new Content(
            markdown: 'mail.outlook-report',
            text: 'mail.outlook-report-text',
            with: [
                'reportHtml' => new HtmlString($this->collapse(view('mail.partials.outlook-report', ['r' => $view, 'imageSrc' => $imageSrc])->render())),
                'reportText' => OutlookReportPresenter::text($view),
            ],
        );
    }

    /**
     * @return array<string, array{bytes: string, mime: string}>
     */
    private function embeddable(): array
    {
        $images = [];

        foreach (self::IMAGE_KEYS as $key) {
            $image = $this->images[$key] ?? null;

            if (is_array($image) && is_string($image['bytes'] ?? null) && is_string($image['mime'] ?? null)) {
                $images[$key] = $image;
            }
        }

        return $images;
    }

    /**
     * As DailySummaryMail::collapse, except that the line breaks of a <pre> (the PWO bulletin text,
     * already normalised to \n by the presenter)
     * become &#10; entities: the browser still breaks the line, but the source stays one line, so a
     * blank line in the bulletin cannot end CommonMark's HTML block.
     */
    private function collapse(string $html): string
    {
        $parts = preg_split('/(<pre\b[^>]*>.*?<\/pre>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];

        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                $parts[$index] = str_replace("\n", '&#10;', $part);

                continue;
            }

            // A bare \r (e.g. in a location label) would otherwise survive and could break the layout.
            $part = preg_replace('/\s*[\r\n]\s*/', ' ', $part) ?? $part;
            $parts[$index] = preg_replace('/>\s+</', '><', $part) ?? $part;
        }

        return trim(implode('', $parts));
    }
}
