<?php

namespace App\Actions\Alerts;

use App\Models\OutlookReportRun;
use App\Support\OutlookMapDisk;
use App\Support\OutlookReportPresenter;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * The Severe Weather Outlook report as the mobile app's alert detail carries it: the presenter's view
 * plus signed, one-hour links to the stored map copies. A link exists only while the maps are within
 * their retention, the key was stored and the file is still on the disk. Never exposes a storage path.
 * A disk that cannot be reached (an S3 outage or auth failure throws from exists()) reads as no map;
 * a misnamed disk still throws, because the disk is resolved outside the guard.
 */
class PresentAppOutlookReport
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(OutlookReportRun $run): array
    {
        $view = OutlookReportPresenter::present($run->payload);
        $expired = $run->mapsExpired();

        $view['days'] = array_map(
            fn (array $day): array => [...$day, 'map_url' => $this->mapUrl($run, $day['key'], $expired)],
            $view['days'],
        );

        return [
            ...$view,
            'extended_map_url' => $this->mapUrl($run, 'day48', $expired),
            'note' => OutlookReportPresenter::NOTE,
            'source_url' => OutlookReportPresenter::SPC_URL,
            'maps_expired' => $expired,
        ];
    }

    private function mapUrl(OutlookReportRun $run, string $key, bool $expired): ?string
    {
        $map = $expired ? null : $run->mapPath($key);

        if ($map === null) {
            return null;
        }

        $disk = OutlookMapDisk::disk();

        try {
            if (! $disk->exists($map['path'])) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return URL::temporarySignedRoute('api.v1.outlook-maps.show', now()->addHour(), ['batchKey' => $run->batch_key, 'key' => $key]);
    }
}
