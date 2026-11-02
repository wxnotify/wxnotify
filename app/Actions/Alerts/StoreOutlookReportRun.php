<?php

namespace App\Actions\Alerts;

use App\Models\AlertRule;
use App\Models\OutlookReportRun;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Stores one outlook report run (once per batch key) and a copy of its SPC maps on the local disk.
 */
class StoreOutlookReportRun
{
    private const EXTENSIONS = ['image/png' => 'png', 'image/gif' => 'gif'];

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array{bytes: string, mime: string}|null>  $graphics
     */
    public function __invoke(AlertRule $rule, string $batchKey, array $payload, array $graphics): OutlookReportRun
    {
        try {
            $run = OutlookReportRun::firstOrCreate(['batch_key' => $batchKey], [
                'user_id' => $rule->user_id,
                'alert_rule_id' => $rule->id,
                'payload' => $payload,
            ]);
        } catch (UniqueConstraintViolationException) {
            $run = OutlookReportRun::where('batch_key', $batchKey)->firstOrFail();
        }

        if (! empty($run->maps)) {
            return $run;
        }

        $maps = [];
        foreach ($graphics as $key => $graphic) {
            $extension = is_array($graphic) ? (self::EXTENSIONS[$graphic['mime'] ?? ''] ?? null) : null;
            if ($extension === null) {
                continue;
            }

            $path = "outlook-maps/{$batchKey}/{$key}.{$extension}";

            try {
                Storage::disk('local')->put($path, $graphic['bytes']);
                $maps[$key] = ['path' => $path, 'mime' => $graphic['mime']];
            } catch (Throwable $e) {
                Log::warning('Outlook report map copy failed', ['exception' => $e::class]);
            }
        }

        if ($maps !== []) {
            $run->update(['maps' => $maps]);
        }

        return $run;
    }
}
