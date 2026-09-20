<?php

namespace App\Support\Delivery\Transports;

use App\Models\NotificationChannel;
use App\Support\Delivery\DeliveryResult;

interface Transport
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function send(NotificationChannel $channel, string $body, ?string $title, array $meta = []): DeliveryResult;
}
