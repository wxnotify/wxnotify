<?php

namespace App\Support\Pipeline;

use Carbon\CarbonInterface;

final class FeedHealth
{
    public function __construct(
        public readonly string $state,
        public readonly string $label,
        public readonly string $color,
    ) {}

    public static function evaluate(string $dockerStatus, ?string $dockerHealth, ?CarbonInterface $lastProductAt, int $staleMinutes): self
    {
        return match (true) {
            $dockerStatus === 'unknown' => new self('unknown', 'Unknown', 'warning'),
            $dockerStatus === 'not_found' => new self('not_found', 'Not found', 'gray'),
            $dockerStatus === 'exited' => new self('stopped', 'Stopped', 'gray'),
            $dockerStatus === 'dead' => new self('down', 'Down', 'danger'),
            in_array($dockerStatus, ['restarting', 'paused', 'created'], true) => new self('restarting', 'Restarting', 'warning'),
            $dockerStatus !== 'running' => new self('unknown', 'Unknown', 'warning'),
            $dockerHealth === 'unhealthy' => new self('unhealthy', 'Unhealthy', 'danger'),
            $dockerHealth === 'starting' => new self('starting', 'Starting', 'warning'),
            $lastProductAt === null || $lastProductAt->lt(now()->subMinutes($staleMinutes)) => new self('stale', 'Stale', 'warning'),
            default => new self('healthy', 'Healthy', 'success'),
        };
    }
}
