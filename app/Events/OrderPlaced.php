<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Sale;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderPlaced
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, array{product_id: int, quantity: int, unit_price: float|int|string}>  $items
     */
    public function __construct(
        public Sale $sale,
        public array $items,
    ) {}
}
