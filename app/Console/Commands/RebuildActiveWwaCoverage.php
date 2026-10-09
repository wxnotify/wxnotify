<?php

namespace App\Console\Commands;

use App\Actions\Pipeline\ApplySegmentToCoverage;
use App\Models\ActiveWwa;
use App\Models\IngestedProductSegment;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Re-derives the zones of the active events from their stored bulletin segments. An event that
 * the old code had collapsed to the last bulletin's zones (21% of them) is brought back to every
 * zone its stored bulletins name, and each zone ends where a stored cancel, expiry or upgrade
 * ended it. Needs the segments to exist first: run bulletins:parse-segments for at least the same
 * number of days.
 *
 * For every Active event with a segment from a bulletin stored in the last N days it deletes the
 * event's zone rows and replays ALL of the event's stored segments, oldest bulletin first,
 * through ApplySegmentToCoverage in one transaction. Cancelled and expired events are left alone.
 * Idempotent, and it only writes active_wwas and active_wwa_areas: no matching, no job, no
 * delivery log, so it cannot send a notification.
 */
class RebuildActiveWwaCoverage extends Command
{
    protected $signature = 'active-wwas:rebuild {--days=2 : Rebuild the active events that have a stored bulletin from the last N days}';

    protected $description = 'Re-derive the zones of the active events from their stored bulletin segments; sends nothing';

    public function handle(ApplySegmentToCoverage $apply): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $rebuilt = 0;

        ActiveWwa::query()
            ->where('status', 'Active')
            ->whereExists(function ($query) use ($cutoff): void {
                $query->selectRaw('1')
                    ->from('ingested_product_segments as s')
                    ->join('ingested_products as b', 'b.id', '=', 's.ingested_product_id')
                    ->whereColumn('s.vtec_office', 'active_wwas.vtec_office')
                    ->whereColumn('s.vtec_phenomenon', 'active_wwas.vtec_phenomenon')
                    ->whereColumn('s.vtec_significance', 'active_wwas.vtec_significance')
                    ->whereColumn('s.vtec_event_number', 'active_wwas.vtec_event_number')
                    ->whereColumn('s.vtec_year', 'active_wwas.vtec_year')
                    ->whereColumn('b.is_test', 'active_wwas.is_test')
                    ->where('b.received_at', '>=', $cutoff);
            })
            ->chunkById(100, function ($events) use ($apply, &$rebuilt): void {
                foreach ($events as $wwa) {
                    DB::transaction(function () use ($wwa, $apply): void {
                        $wwa->areas()->delete();

                        foreach ($this->storedSegments($wwa) as $segment) {
                            $apply($segment->ingestedProduct, $segment);
                        }
                    });

                    $rebuilt++;
                }
            });

        $this->info("Rebuilt the zones of {$rebuilt} active ".Str::plural('event', $rebuilt).'.');

        return self::SUCCESS;
    }

    /**
     * Every stored segment of the event (same VTEC key, same test flag), oldest bulletin first.
     *
     * @return Collection<int, IngestedProductSegment>
     */
    private function storedSegments(ActiveWwa $wwa): Collection
    {
        $key = collect(ActiveWwa::vtecKeyFor($wwa))
            ->mapWithKeys(fn (mixed $value, string $column): array => ["ingested_product_segments.{$column}" => $value])
            ->all();

        return IngestedProductSegment::query()
            ->select('ingested_product_segments.*')
            ->join('ingested_products as bulletins', 'bulletins.id', '=', 'ingested_product_segments.ingested_product_id')
            ->where($key)
            ->where('bulletins.is_test', $wwa->is_test)
            ->orderBy('bulletins.received_at')
            ->orderBy('bulletins.id')
            ->orderBy('ingested_product_segments.position')
            ->with('ingestedProduct')
            ->get();
    }
}
