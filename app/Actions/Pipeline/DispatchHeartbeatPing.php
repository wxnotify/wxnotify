<?php

namespace App\Actions\Pipeline;

use App\Enums\PipelineFeed;
use App\Jobs\SendHeartbeatPing;
use App\Support\Integrations;
use Illuminate\Support\Facades\Bus;
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
        } catch (Throwable $e) {
            report($e);
        }
    }
}
