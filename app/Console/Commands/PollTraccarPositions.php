<?php

namespace App\Console\Commands;

use App\Actions\Traccar\ListTraccarDevices;
use App\Actions\Traccar\PollTraccarServer;
use App\Enums\Integration;
use App\Enums\LocationType;
use App\Jobs\ReverseGeocodeLocation;
use App\Models\Location;
use App\Models\TraccarServer;
use App\Support\Integrations;
use Carbon\CarbonImmutable;
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
        // type = mobile is checked explicitly: a location switched to stationary
        // before saving cleared its Traccar link must never have its pin moved.
        $dueLocations = Location::query()
            ->where('type', LocationType::Mobile->value)
            ->whereNotNull('traccar_server_id')
            ->whereNotNull('traccar_device_id')
            ->whereNotNull('mobile_update_interval_minutes')
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
            ['positions' => $positions, 'error' => $error] = $poll->poll($server);
        } catch (Throwable $e) {
            $this->recordFailure($server, $e->getMessage());

            return 0;
        }

        if ($positions === null) {
            $this->recordFailure($server, $error ?? 'Traccar server unreachable or returned an error.');

            return 0;
        }

        $server->forceFill(['last_polled_at' => now(), 'last_poll_error' => null])->save();

        $updated = 0;
        $unreported = [];

        foreach ($locations as $location) {
            $position = $positions[$location->traccar_device_id] ?? null;

            if ($position === null) {
                $unreported[] = $location;

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
                    // When Traccar says the device was located, kept as it was when this
                    // position carries no usable fixTime.
                    'mobile_position_fixed_at' => $this->fixedAt($position['fixTime']) ?? $location->mobile_position_fixed_at,
                    'traccar_device_missing_at' => null,
                ])->save();
            } finally {
                $location->enableLogging();
            }

            $this->queueAddressLookup($location);

            $updated++;
        }

        $this->reconcileMissingDevices($server, $unreported);

        return $updated;
    }

    /**
     * /api/positions lists only devices that have a position, so a device that was
     * deleted from the server (demo servers re-issue device IDs) looks the same as one
     * that has not reported yet. For the locations that got no position, ask the server
     * for its device list and mark the ones whose device is gone, keeping the time it
     * was first noticed; a device that is there clears a stale mark. When the list
     * cannot be fetched nothing changes: whether the device exists is unknown.
     *
     * @param  list<Location>  $unreported
     */
    private function reconcileMissingDevices(TraccarServer $server, array $unreported): void
    {
        if ($unreported === []) {
            return;
        }

        $devices = app(ListTraccarDevices::class)($server);

        if ($devices === null) {
            return;
        }

        $deviceIds = array_map('intval', array_column($devices, 'id'));

        foreach ($unreported as $location) {
            $missingAt = in_array((int) $location->traccar_device_id, $deviceIds, true)
                ? null
                : ($location->traccar_device_missing_at ?? now());

            if ($missingAt?->timestamp === $location->traccar_device_missing_at?->timestamp) {
                continue;
            }

            // Bookkeeping, not a subscriber edit: keep it out of the activity log.
            $location->disableLogging();

            try {
                $location->forceFill(['traccar_device_missing_at' => $missingAt])->save();
            } finally {
                $location->enableLogging();
            }
        }
    }

    /**
     * A new position needs a street address when the location was never resolved or has
     * moved far enough since it was. Queued, so a slow geocoder never slows polling, and
     * never allowed to fail the poll.
     */
    private function queueAddressLookup(Location $location): void
    {
        try {
            if (Integrations::enabled(Integration::ReverseGeocoding) && $location->needsAddressLookup()) {
                ReverseGeocodeLocation::dispatch($location->id);
            }
        } catch (Throwable) {
            // The next poll tries again.
        }
    }

    private function fixedAt(?string $fixTime): ?CarbonImmutable
    {
        if (blank($fixTime)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($fixTime);
        } catch (Throwable) {
            return null;
        }
    }

    private function recordFailure(TraccarServer $server, string $error): void
    {
        $server->forceFill(['last_polled_at' => now(), 'last_poll_error' => $error])->save();
    }
}
