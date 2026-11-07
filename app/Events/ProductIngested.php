<?php

namespace App\Events;

use App\Models\IngestedProduct;
use App\Support\IssuingOffice;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Announces a processed product to the Live Weather Products page. The payload is plain data
 * (computed here, so the queued broadcast needs no model) and never carries the product text: the
 * page reads the row itself by id.
 *
 * The broadcast rides its own `broadcasts` queue, which the worker reads last, so a slow or hanging
 * Reverb can never delay the deliveries or the product processing. It is tried once (a stale
 * product announcement is worthless; the page's poll recovers) and cut off well inside the
 * worker's timeout.
 */
#[Tries(1)]
#[Timeout(15)]
class ProductIngested implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /** @var array{id: int, name: string, code: ?string, office: ?string, issued_at: string, headline: ?string} */
    private array $payload;

    public function __construct(IngestedProduct $product)
    {
        $segments = $product->segments()->with('product')->get();

        $this->payload = [
            'id' => $product->id,
            'name' => $product->displayTitle(),
            'code' => filled($product->awipsid) ? strtoupper($product->awipsid) : null,
            'office' => filled($product->cccc) ? IssuingOffice::code($product->cccc) : null,
            'issued_at' => $product->received_at->toIso8601String(),
            'headline' => $segments->isEmpty() ? null : implode(' + ', $product->eventNamesFor($segments)),
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    /**
     * With the default broadcaster off (`null`) the event is not even queued.
     */
    public function broadcastWhen(): bool
    {
        return ! self::broadcasterIsOff();
    }

    /**
     * True when the default broadcaster is `null`. BROADCAST_CONNECTION=null in the environment
     * reaches the config as PHP null, the config fallback as the string 'null': both mean off.
     */
    public static function broadcasterIsOff(): bool
    {
        return in_array(config('broadcasting.default'), [null, 'null'], true);
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('live-products')];
    }

    public function broadcastAs(): string
    {
        return 'ProductIngested';
    }

    /**
     * @return array{id: int, name: string, code: ?string, office: ?string, issued_at: string, headline: ?string}
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
