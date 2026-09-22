<?php

namespace App\Actions\Pipeline;

use App\Enums\PipelineFeed;
use App\Jobs\SendHeartbeatPing;
use App\Support\Integrations;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchHeartbeatPing
{
    public function __invoke(string $feed): void
    {
        try {
            $pipelineFeed = PipelineFeed::tryFrom($feed);

            if ($pipelineFeed === null || Integrations::heartbeatUrl($pipelineFeed) === null) {
                return;
            }

            Bus::dispatch(new SendHeartbeatPing($feed));
        } catch (Throwable) {
            $this->warnOnce($feed);
        }
    }

    private function warnOnce(string $feed): void
    {
        try {
            if (Cache::add('pipeline.heartbeat-dispatch-warning', true, 300)) {
                Log::warning('Failed to dispatch a heartbeat ping', ['feed' => $feed]);
            }
        } catch (Throwable) {
            // A failing cache or log must never fail the dispatch action.
        }
    }
}
