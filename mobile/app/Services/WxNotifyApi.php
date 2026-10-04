<?php

namespace App\Services;

use App\Exceptions\Api\Offline;
use App\Exceptions\Api\Rejected;
use App\Exceptions\Api\Retryable;
use App\Exceptions\Api\Unauthenticated;
use App\Support\TokenStore;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\Network;

/**
 * The app's only way to reach WxNotify. Failures become typed exceptions the screens handle:
 * Unauthenticated (clear the token, go to Sign in), Offline, Retryable (429/5xx), Rejected.
 */
class WxNotifyApi
{
    public function __construct(private TokenStore $tokens) {}

    /**
     * @return array{token: string, expires_at: string, user: array<string, mixed>}
     */
    public function exchangeCode(string $code, string $verifier): array
    {
        return $this->send(
            fn (PendingRequest $request) => $request->post('/api/v1/auth/token', ['code' => $code, 'code_verifier' => $verifier]),
            authenticated: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function me(): array
    {
        return $this->send(fn (PendingRequest $request) => $request->get('/api/v1/me'))['data'];
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array{next_cursor: ?string}}
     */
    public function alerts(?string $before = null, int $limit = 25): array
    {
        return $this->send(fn (PendingRequest $request) => $request->get('/api/v1/alerts', array_filter(
            ['limit' => $limit, 'before' => $before],
            fn ($value) => $value !== null,
        )));
    }

    /**
     * @return array<string, mixed>
     */
    public function alert(int $id): array
    {
        return $this->send(fn (PendingRequest $request) => $request->get("/api/v1/alerts/{$id}"))['data'];
    }

    public function signOut(): void
    {
        $this->send(fn (PendingRequest $request) => $request->delete('/api/v1/auth/token'));
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(Closure $call, bool $authenticated = true): array
    {
        $this->assertOnline();

        $request = Http::baseUrl(config('wxnotify.api_url'))->acceptJson()->asJson()->timeout(15);

        if ($authenticated) {
            $token = $this->tokens->token();

            if ($token === null) {
                throw new Unauthenticated;
            }

            $request = $request->withToken($token);
        }

        try {
            $response = $call($request);
        } catch (ConnectionException) {
            throw new Offline;
        }

        return match (true) {
            $response->successful() => $response->json() ?? [],
            $response->status() === 401 => throw new Unauthenticated,
            $response->status() === 429, $response->serverError() => throw new Retryable,
            default => throw new Rejected,
        };
    }

    private function assertOnline(): void
    {
        $status = Network::status();

        if ($status !== null && ($status->connected ?? true) === false) {
            throw new Offline;
        }
    }
}
