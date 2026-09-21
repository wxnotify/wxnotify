<?php

namespace App\Support\Pipeline;

use App\Enums\PipelineFeed;

final class PipelineSummary
{
    /**
     * @param  array<int, string>  $notes
     */
    public function __construct(
        public readonly string $color,
        public readonly string $headline,
        public readonly array $notes,
    ) {}

    /**
     * @param  array<string, FeedHealth>  $healthByFeed  keyed by PipelineFeed value
     */
    public static function evaluate(array $healthByFeed): self
    {
        $attention = [];
        $notes = [];
        $color = 'success';

        foreach (PipelineFeed::cases() as $feed) {
            $health = $healthByFeed[$feed->value];
            $stoppedLike = in_array($health->state, ['stopped', 'not_found'], true);

            if ($stoppedLike && ! $feed->isPrimary()) {
                $notes[] = $feed->label().' is '.($health->state === 'not_found' ? 'not deployed' : 'stopped');

                continue;
            }

            $isRed = $health->color === 'danger' || ($stoppedLike && $feed->isPrimary());
            $isAmber = $health->color === 'warning';

            if ($isRed || $isAmber) {
                $attention[] = $feed->label().' ('.$health->label.')';
                $color = $isRed ? 'danger' : ($color === 'danger' ? 'danger' : 'warning');
            }
        }

        $headline = match (true) {
            $attention !== [] => 'Needs attention: '.implode(', ', $attention),
            $notes !== [] => 'All running feeds healthy',
            default => 'All feeds healthy',
        };

        return new self($color, $headline, $notes);
    }
}
