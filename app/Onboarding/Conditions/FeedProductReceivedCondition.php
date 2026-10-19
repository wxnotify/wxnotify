<?php

namespace App\Onboarding\Conditions;

use App\Support\Pipeline\PipelineHeartbeat;
use Illuminate\Database\Eloquent\Model;

/**
 * A NWWS heartbeat exists, so at least one product has been received.
 */
final class FeedProductReceivedCondition extends SafeCondition
{
    public static function label(): string
    {
        return 'A product has arrived from the weather feed';
    }

    protected function check(Model $subject): bool
    {
        return PipelineHeartbeat::lastReceivedAt('nwws') !== null;
    }
}
