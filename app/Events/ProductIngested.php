<?php

namespace App\Events;

use App\Models\IngestedProduct;
use App\Support\IssuingOffice;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Announces a processed product to the Live Weather Products page. The payload is plain data
 * (computed here, so the queued broadcast needs no model) and never carries the product text: the
 * page reads the row itself by id.
 */
class ProductIngested implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /** @var array{id: int, name: string, code: ?string, office: ?string, issued_at: string, headline: ?string} */
    private array $payload;

    public function __construct(IngestedProduct $product)
    {
        $names = $product->eventNamesFor($product->segments()->with('product')->get());

        $this->payload = [
            'id' => $product->id,
            'name' => $product->displayTitle(),
            'code' => filled($product->awipsid) ? strtoupper($product->awipsid) : null,
            'office' => filled($product->cccc) ? IssuingOffice::code($product->cccc) : null,
            'issued_at' => $product->received_at->toIso8601String(),
            'headline' => $product->segments()->exists() ? implode(' + ', $names) : null,
        ];
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
