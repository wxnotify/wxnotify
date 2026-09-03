<?php

namespace App\Support\Pipeline;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to the local Docker Engine API over the host's unix socket
 * (bind-mounted into this container — see compose.yaml) to control one
 * fixed, known sidecar container. Not a general-purpose Docker client.
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
     * Returns Docker's own State.Status value (running/exited/dead/...),
     * or 'not_found' when the container doesn't exist yet (e.g. before
     * its image has ever been built) — callers need to render *something*
     * for that case rather than crash.
     */
    public function status(string $container): string
    {
        $response = $this->request('get', "/containers/{$container}/json");

        if ($response->status() === 404) {
            return 'not_found';
        }

        $response->throw();

        $status = $response->json('State.Status');

        if (! is_string($status) || $status === '') {
            throw new RuntimeException("Docker returned an unexpected response shape for container [{$container}].");
        }

        return $status;
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
}
