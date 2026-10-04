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
        $body = $this->send(
            fn (PendingRequest $request) => $request->post('/api/v1/auth/token', ['code' => $code, 'code_verifier' => $verifier]),
            authenticated: false,
        );

        if (! is_string($body['token'] ?? null) || ! is_array($body['user'] ?? null)) {
            throw new Retryable;
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    public function me(): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get('/api/v1/me')));
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array{next_cursor: ?string}}
     */
    public function alerts(?string $before = null, int $limit = 25): array
    {
        $body = $this->send(fn (PendingRequest $request) => $request->get('/api/v1/alerts', array_filter(
            ['limit' => $limit, 'before' => $before],
            fn ($value) => $value !== null,
        )));

        $this->data($body);

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    public function alert(int $id): array
    {
        return $this->data($this->send(fn (PendingRequest $request) => $request->get("/api/v1/alerts/{$id}")));
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
            $response->successful() => $this->decode($response),
            $response->status() === 401 => throw new Unauthenticated,
            $response->status() === 429, $response->serverError() => throw new Retryable,
            default => throw new Rejected,
        };
    }

    /**
     * A success with no body (the sign-out 204) is empty; a body that is not JSON (a captive portal's
     * HTML page, a misconfigured host) is a transient failure, never a half-built array.
     *
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        if ($response->status() === 204 || trim($response->body()) === '') {
            return [];
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new Retryable;
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function data(array $body): array
    {
        if (! is_array($body['data'] ?? null)) {
            throw new Retryable;
        }

        return $body['data'];
    }

    private function assertOnline(): void
    {
        $status = Network::status();

        if ($status !== null && ($status->connected ?? true) === false) {
            throw new Offline;
        }
    }
}
