<?php

namespace App\Jobs;

use App\Enums\PipelineFeed;
use App\Support\Delivery\HeartbeatUrlGuard;
use App\Support\Integrations;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendHeartbeatPing implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(public readonly string $feed)
    {
        // Queueable's $queue property has no type, so it can't be redeclared with
        // one here (PHP treats that as an incompatible trait composition) — set
        // it via the trait's own setter instead, to isolate ping traffic onto its
        // own worker away from the shared default queue.
        $this->onQueue('heartbeat');
    }

    public function handle(HeartbeatUrlGuard $guard): void
    {
        $feed = PipelineFeed::tryFrom($this->feed);
        $url = $feed === null ? null : Integrations::heartbeatUrl($feed);

        if ($url === null) {
            return;
        }

        try {
            // Null when the guard refuses the URL or the host does not resolve. Otherwise the
            // ping is pinned to exactly the addresses the guard vetted, not a fresh DNS answer.
            $pin = $guard->requestOptions($url);

            if ($pin === null) {
                $this->warnOnce();

                return;
            }

            $response = $this->pendingRequest()->withOptions($pin)->get($url);

            if (! $response->successful()) {
                $this->warnOnce();
            }
        } catch (Throwable) {
            // The exception message can carry the ping URL, so it is deliberately not logged.
            $this->warnOnce();
        }
    }

    protected function pendingRequest(): PendingRequest
    {
        return Http::timeout(5)->withOptions(['allow_redirects' => false]);
    }

    private function warnOnce(): void
    {
        try {
            if (Cache::add("pipeline.heartbeat-ping-warning.{$this->feed}", true, 300)) {
                Log::warning('Heartbeat ping failed', ['feed' => $this->feed]);
            }
        } catch (Throwable) {
            // A failing cache or log must never fail the job.
        }
    }
}
