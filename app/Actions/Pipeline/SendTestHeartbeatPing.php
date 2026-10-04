<?php

namespace App\Actions\Pipeline;

use App\Support\Delivery\HeartbeatUrlGuard;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends one real GET to a heartbeat ping URL the admin has typed, exactly the way
 * SendHeartbeatPing does (same guard, pinned to the addresses it vetted, no
 * redirects, short timeout), and reports the outcome.
 *
 * A Healthchecks ping URL carries a secret UUID, so the result is built from fixed
 * strings and the numeric HTTP status only: never the URL, the exception text or
 * the response body, and nothing is logged. The URL is a real ping, so a successful
 * test counts as a check-in.
 */
class SendTestHeartbeatPing
{
    public function __construct(private readonly HeartbeatUrlGuard $guard) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function __invoke(string $url): array
    {
        // A test follows an edit, so never trust a verdict cached from before it.
        $this->guard->forget($url);

        $pin = $this->guard->requestOptions($url);

        if ($pin === null) {
            return ['ok' => false, 'message' => $this->guard->violation($url) ?? 'That host name could not be resolved.'];
        }

        try {
            $response = Http::timeout(5)->withOptions(['allow_redirects' => false, ...$pin])->get($url);
        } catch (Throwable) {
            return ['ok' => false, 'message' => 'Could not reach the server.'];
        }

        return [
            'ok' => $response->successful(),
            'message' => "The server answered HTTP {$response->status()}.",
        ];
    }
}
