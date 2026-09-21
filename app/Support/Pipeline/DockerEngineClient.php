<?php

namespace App\Support\Pipeline;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to the local Docker Engine API through a docker-socket-proxy
 * sidecar (see compose.yaml) that mounts the real socket itself and
 * exposes a restricted HTTP API — laravel.test never touches the raw
 * socket directly. Controls the fixed, known pipeline containers. Not a
 * general-purpose Docker client.
 */
class DockerEngineClient
{
    private const API_VERSION = 'v1.43';

    private function request(string $method, string $path): Response
    {
        return Http::baseUrl(config('services.docker.proxy_url'))
            ->timeout(5)
            ->{$method}('/'.self::API_VERSION.$path);
    }

    /**
     * @return array{status: string, health: ?string}
     */
    public function state(string $container): array
    {
        $response = $this->request('get', "/containers/{$container}/json");

        if ($response->status() === 404) {
            return ['status' => 'not_found', 'health' => null];
        }

        $response->throw();

        $status = $response->json('State.Status');

        if (! is_string($status) || $status === '') {
            throw new RuntimeException("Docker returned an unexpected response shape for container [{$container}].");
        }

        $health = $response->json('State.Health.Status');

        return ['status' => $status, 'health' => is_string($health) && $health !== '' ? $health : null];
    }

    /**
     * Returns Docker's own State.Status value (running/exited/dead/...),
     * or 'not_found' when the container doesn't exist yet (e.g. before
     * its image has ever been built) — callers need to render *something*
     * for that case rather than crash.
     */
    public function status(string $container): string
    {
        return $this->state($container)['status'];
    }

    public function start(string $container): void
    {
        $this->request('post', "/containers/{$container}/start")->throw();
    }

    public function stop(string $container): void
    {
        $this->request('post', "/containers/{$container}/stop")->throw();
    }

    public function restart(string $container): void
    {
        $this->request('post', "/containers/{$container}/restart")->throw();
    }

    /**
     * @return array<int, string>
     */
    public function logs(string $container, int $tail = 200): array
    {
        $response = $this->request('get', "/containers/{$container}/logs?stdout=1&stderr=1&tail={$tail}");

        if ($response->status() === 404) {
            return [];
        }

        $response->throw();

        $lines = [];

        foreach (explode("\n", $this->demultiplex($response->body())) as $line) {
            $line = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $line) ?? '';
            $line = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $line) ?? '';
            $line = mb_convert_encoding($line, 'UTF-8', 'UTF-8');
            $lines[] = mb_strlen($line) > 500 ? mb_substr($line, 0, 500).'…' : $line;
        }

        if (end($lines) === '') {
            array_pop($lines);
        }

        return array_slice($lines, -$tail);
    }

    private function demultiplex(string $body): string
    {
        if (strlen($body) < 8 || ! in_array(ord($body[0]), [0, 1, 2], true) || substr($body, 1, 3) !== "\0\0\0") {
            return $body;
        }

        $output = '';
        $offset = 0;
        $length = strlen($body);

        while ($offset + 8 <= $length) {
            $size = unpack('N', substr($body, $offset + 4, 4))[1];
            $output .= substr($body, $offset + 8, $size);
            $offset += 8 + $size;
        }

        return $output;
    }
}
