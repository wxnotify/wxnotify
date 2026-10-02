<?php

namespace App\Mail;

use App\Support\DailySummaryPresenter;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\HtmlString;
use Symfony\Component\Mime\Email;

/**
 * The Full Daily Weather Summary: header, alerts, current conditions, radar,
 * hourly and 7-day sections. Rendered inside the shared Markdown mail layout
 * so it inherits the branded header (.ai/rules/delivery.md).
 *
 * Markdown (CommonMark) would mangle indented HTML and treat blank lines inside
 * an HTML block as the end of that block, so the section HTML is rendered from
 * a plain partial and collapsed to a single unbroken line before it is placed
 * in the Markdown view.
 *
 * The radar is attached via a Symfony inline embed referenced as
 * cid:radar-snapshot; Laravel's $message->embed() is not available in
 * Markdown mail views.
 */
class DailySummaryMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $report
     * @param  array{bytes: string, mime: string}|null  $radar
     */
    public function __construct(
        public readonly ?string $messageTitle,
        public readonly array $report,
        public readonly string $briefText,
        public readonly ?array $radar = null,
    ) {
        if ($radar !== null) {
            $this->withSymfonyMessage(fn (Email $message) => $message->embed($radar['bytes'], 'radar-snapshot', $radar['mime']));
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
        $view = DailySummaryPresenter::present($this->report);
        $hasRadar = $this->radar !== null;

        return new Content(
            markdown: 'mail.daily-summary',
            text: 'mail.daily-summary-text',
            with: [
                'summaryHtml' => new HtmlString($this->collapse(view('mail.partials.daily-summary', ['s' => $view, 'hasRadar' => $hasRadar])->render())),
                'summaryText' => DailySummaryPresenter::text($view, $hasRadar),
            ],
        );
    }

    private function collapse(string $html): string
    {
        $html = preg_replace('/\s*\n\s*/', ' ', $html) ?? $html;

        return trim(preg_replace('/>\s+</', '><', $html) ?? $html);
    }
}
