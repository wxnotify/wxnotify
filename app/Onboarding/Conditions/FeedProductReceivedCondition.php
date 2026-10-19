<?php

namespace App\Onboarding\Conditions;

use App\Enums\PipelineFeed;
use App\Support\Pipeline\PipelineHeartbeat;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Any pipeline feed (NWWS, EMWIN Byteblaster or the EMWIN HTTP backup) has a heartbeat, so at
 * least one product has been received. A feed whose heartbeat cannot be read counts as not received.
 */
final class FeedProductReceivedCondition extends SafeCondition
{
    public static function label(): string
    {
        return 'A product has arrived from the weather feed';
    }

    protected function check(Model $subject): bool
    {
        foreach (PipelineFeed::cases() as $feed) {
            try {
                if (PipelineHeartbeat::lastReceivedAt($feed->value) !== null) {
                    return true;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return false;
    }
}
