<?php

namespace App\Support\Pipeline;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class PipelineHeartbeat
{
    public static function record(string $feed): void
    {
        Cache::forever("pipeline.heartbeat.{$feed}", now()->toIso8601String());
    }

    public static function lastReceivedAt(string $feed): ?Carbon
    {
        $value = Cache::get("pipeline.heartbeat.{$feed}");

        return $value === null ? null : Carbon::parse($value);
    }
}
