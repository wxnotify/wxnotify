<?php

namespace App\Actions\Pipeline;

use App\Models\PipelineDelivery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecordPipelineDelivery
{
    public function __invoke(string $feed, ?string $wmoHeaderKey, string $filePath, bool $ingested): void
    {
        try {
            PipelineDelivery::create([
                'feed' => $feed,
                'wmo_header_key' => $wmoHeaderKey,
                'file_name' => mb_substr(basename($filePath), 0, 255),
                'outcome' => $ingested ? PipelineDelivery::OUTCOME_INGESTED : PipelineDelivery::OUTCOME_DUPLICATE,
                'received_at' => now(),
            ]);
        } catch (Throwable $e) {
            if (Cache::add('pipeline.delivery-record-warning', true, 300)) {
                Log::warning('Failed to record a pipeline delivery', ['feed' => $feed, 'error' => $e->getMessage()]);
            }
        }
    }
}
