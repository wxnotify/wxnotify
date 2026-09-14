<?php

namespace App\Console\Commands;

use App\Actions\Traccar\PollTraccarServer;
use App\Models\Location;
use App\Models\TraccarServer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Polls every Traccar server that has at least one due mobile Location
 * attached, one /api/positions call per server regardless of how many of
 * its devices are due (the endpoint already returns every device's
 * position at once). Intended to run every 5 minutes (see bootstrap/app.php)
 * -- the finest per-location mobile_update_interval_minutes allowed, so the
 * due-check below is what actually varies polling frequency, not the
 * schedule cadence itself.
 */
class PollTraccarPositions extends Command
{
    protected $signature = 'traccar:poll-positions';

    protected $description = 'Poll due Traccar servers and update their mobile Locations\' positions';

    public function handle(PollTraccarServer $poll): int
    {
        $dueLocations = Location::query()
            ->whereNotNull('traccar_server_id')
            ->whereNotNull('traccar_device_id')
            ->where(function ($query) {
                $query->whereNull('mobile_position_updated_at')
                    ->orWhereRaw(
                        "mobile_position_updated_at <= now() - (mobile_update_interval_minutes::text || ' minutes')::interval",
                    );
            })
            ->get();

        $updated = 0;

        foreach ($dueLocations->groupBy('traccar_server_id') as $serverId => $locations) {
            $server = TraccarServer::find($serverId);

            if ($server === null) {
                continue;
            }

            $updated += $this->pollServer($server, $locations, $poll);
        }

        $this->info("Updated {$updated} mobile location(s).");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Location>  $locations
     */
    private function pollServer(TraccarServer $server, Collection $locations, PollTraccarServer $poll): int
    {
        try {
            $positions = $poll($server);
        } catch (Throwable $e) {
            $this->recordFailure($server, $e->getMessage());

            return 0;
        }

        if ($positions === null) {
            $this->recordFailure($server, 'Traccar server unreachable or returned an error.');

            return 0;
        }

        $server->forceFill(['last_polled_at' => now(), 'last_poll_error' => null])->save();

        $updated = 0;

        foreach ($locations as $location) {
            $position = $positions[$location->traccar_device_id] ?? null;

            if ($position === null) {
                continue;
            }

            // Location's activity log excludes mobile_position_updated_at
            // (see Task 3) but NOT lat/lng -- those stay logged for a
            // subscriber's own manual repositioning of a stationary
            // location. A poll writes lat/lng too, though, every 5-30
            // minutes forever, so this exact write needs its own
            // logging disabled rather than relying on a model-level field
            // exclusion (which can't tell "the poller wrote this" apart
            // from "the subscriber corrected their pin"). disableLogging()/
            // enableLogging() are Spatie Activitylog instance methods
            // (vendor/spatie/laravel-activitylog/src/Models/Concerns/LogsActivity.php).
            $location->disableLogging();

            try {
                $location->forceFill([
                    'lat' => $position['lat'],
                    'lng' => $position['lng'],
                    'mobile_position_updated_at' => now(),
                ])->save();
            } finally {
                $location->enableLogging();
            }

            $updated++;
        }

        return $updated;
    }

    private function recordFailure(TraccarServer $server, string $error): void
    {
        $server->forceFill(['last_polled_at' => now(), 'last_poll_error' => $error])->save();
    }
}
