<?php

namespace App\Support\Pipeline;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DockerEngineClient
{
    private function request(string $method, string $path): Response
    {
        return Http::withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => '/var/run/docker.sock']])
            ->{$method}("http://localhost/v1.43{$path}");
    }

    public function status(string $container): string
    {
        $response = $this->request('get', "/containers/{$container}/json");

        if ($response->status() === 404) {
            return 'not_found';
        }

        $response->throw();

        return $response->json('State.Status');
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
