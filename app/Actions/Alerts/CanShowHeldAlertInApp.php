<?php

namespace App\Actions\Alerts;

use App\Models\NotificationChannel;
use App\Support\Delivery\ChannelDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Whether an alert held by quiet time is shown in the mobile app: only for a phone push channel that could have
 * received the push right now (no refusal from the delivery gates, and a push token). It makes no network call,
 * as it runs while a product is being matched.
 */
class CanShowHeldAlertInApp
{
    public function __construct(private readonly ChannelDispatcher $dispatcher) {}

    /**
     * Never throws: the caller still has to write the held row, so a failing check answers no. The transaction
     * (a savepoint inside an open one) keeps a failed query from aborting PostgreSQL's transaction around it.
     */
    public function __invoke(NotificationChannel $channel): bool
    {
        try {
            return DB::transaction(fn (): bool => $channel->channel_type === 'mobile_push'
                && $this->dispatcher->refusalFor($channel) === null
                // refusalFor() does not look for a token; only the transport does, at send time.
                && filled($channel->config['fcm_token'] ?? null));
        } catch (Throwable $e) {
            // The class only: a message can carry the token.
            Log::warning('Held alert app check failed: '.$e::class);

            return false;
        }
    }
}
