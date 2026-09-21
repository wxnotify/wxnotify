<?php

namespace App\Support\Pipeline;

use App\Enums\PipelineFeed;
use App\Models\PipelineDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PipelineActivity
{
    private const WAITING_MINUTES = 10;

    private ?bool $tableExists = null;

    /**
     * @return array<int, int>
     */
    public function hourlyCounts(PipelineFeed $feed): array
    {
        $empty = array_fill(0, 24, 0);

        if (! $this->available()) {
            return $empty;
        }

        try {
            $start = now()->startOfHour()->subHours(23);

            $rows = PipelineDelivery::query()
                ->where('feed', $feed->value)
                ->where('received_at', '>=', $start)
                ->selectRaw("date_trunc('hour', received_at) as bucket, count(*) as total")
                ->groupBy('bucket')
                ->pluck('total', 'bucket');
        } catch (Throwable $e) {
            report($e);

            return $empty;
        }

        $counts = [];

        for ($i = 0; $i < 24; $i++) {
            $key = $start->copy()->addHours($i)->format('Y-m-d H:i:s');
            $counts[] = (int) ($rows[$key] ?? 0);
        }

        return $counts;
    }

    /**
     * @return Collection<int, PipelineDelivery>
     */
    public function recent(PipelineFeed $feed, int $limit = 8): Collection
    {
        if (! $this->available()) {
            return collect();
        }

        try {
            return PipelineDelivery::query()
                ->where('feed', $feed->value)
                ->orderByDesc('received_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get();
        } catch (Throwable $e) {
            report($e);

            return collect();
        }
    }

    /**
     * @return Collection<int, array{label: string, comparable: bool, first_seen: Carbon, cells: array<string, array{state: string, at: ?Carbon}>}>
     */
    public function emwinComparison(int $limit = 10): Collection
    {
        if (! $this->available()) {
            return collect();
        }

        try {
            $feeds = [PipelineFeed::Emwin->value, PipelineFeed::EmwinHttp->value];
            $since = now()->subDay();

            $keyed = PipelineDelivery::query()
                ->whereIn('feed', $feeds)->whereNotNull('wmo_header_key')->where('received_at', '>=', $since)
                ->selectRaw('wmo_header_key, min(received_at) as first_seen')
                ->groupBy('wmo_header_key')->orderByDesc('first_seen')->limit($limit)
                ->pluck('first_seen', 'wmo_header_key');

            $unkeyed = PipelineDelivery::query()
                ->whereIn('feed', $feeds)->whereNull('wmo_header_key')->where('received_at', '>=', $since)
                ->orderByDesc('received_at')->limit($limit)->get();

            $deliveries = PipelineDelivery::query()
                ->whereIn('feed', $feeds)->whereIn('wmo_header_key', $keyed->keys())->where('received_at', '>=', $since)
                ->get()->groupBy('wmo_header_key');
        } catch (Throwable $e) {
            report($e);

            return collect();
        }

        $rows = collect();

        foreach ($keyed as $key => $firstSeen) {
            $firstSeen = Carbon::parse($firstSeen);
            $group = $deliveries[$key] ?? collect();

            if ($group->isEmpty()) {
                continue;
            }

            $byFeed = $group->groupBy('feed')->map(fn (Collection $rows) => $rows->min('received_at'));
            $cells = [];

            foreach ($feeds as $feed) {
                $at = $byFeed[$feed] ?? null;
                $cells[$feed] = [
                    'state' => $at !== null ? 'delivered' : ($firstSeen->gt(now()->subMinutes(self::WAITING_MINUTES)) ? 'waiting' : 'missing'),
                    'at' => $at,
                ];
            }

            $rows->push(['label' => strtoupper((string) $key), 'comparable' => true, 'first_seen' => $firstSeen, 'cells' => $cells]);
        }

        foreach ($unkeyed as $delivery) {
            $cells = [];

            foreach ($feeds as $feed) {
                $cells[$feed] = $feed === $delivery->feed
                    ? ['state' => 'delivered', 'at' => $delivery->received_at]
                    : ['state' => 'unmatched', 'at' => null];
            }

            $rows->push(['label' => $delivery->file_name, 'comparable' => false, 'first_seen' => $delivery->received_at, 'cells' => $cells]);
        }

        return $rows->sortByDesc('first_seen')->take($limit)->values();
    }

    public function ingestedToday(): int
    {
        if (! $this->available()) {
            return 0;
        }

        try {
            return PipelineDelivery::query()
                ->where('outcome', PipelineDelivery::OUTCOME_INGESTED)
                ->where('received_at', '>=', now()->startOfDay())
                ->count();
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    private function available(): bool
    {
        return $this->tableExists ??= Schema::hasTable('pipeline_deliveries');
    }
}
