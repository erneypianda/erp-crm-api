<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessStockReservation implements ShouldQueue
{
    public string $queue = 'inventory';

    public int $tries = 1;

    public function handle(OrderPlaced $event): void
    {
        $sale = $event->sale->fresh();

        if ($sale === null || $sale->status !== 'PENDING') {
            return;
        }

        DB::transaction(function () use ($event, $sale): void {
            foreach ($event->items as $item) {
                $this->reserveItem($sale, $item);
            }

            $sale->update(['status' => 'COMPLETED']);
        });
    }

    public function failed(OrderPlaced $event, Throwable $exception): void
    {
        $sale = $event->sale->fresh(['items']);

        Log::error('Stock reservation failed.', [
            'order_uuid' => $sale?->uuid,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);

        if ($sale === null || in_array($sale->status, ['COMPLETED', 'CANCELLED'], true)) {
            return;
        }

        foreach ($sale->items as $saleItem) {
            $this->releaseItem($saleItem);
        }

        $sale->update(['status' => 'FAILED']);
    }

    /**
     * @param  array{product_id: int|string, quantity: int|string, unit_price: float|int|string}  $item
     */
    private function reserveItem(Sale $sale, array $item): void
    {
        $productId = (int) $item['product_id'];
        $requestedQuantity = (int) $item['quantity'];
        $unitPrice = round((float) $item['unit_price'], 2);

        Cache::lock('product_lock:'.$productId, 10)->block(5, function () use ($sale, $productId, $requestedQuantity, $unitPrice): void {
            $product = Product::query()->lockForUpdate()->find($productId);

            if ($product === null || (int) $product->stock < $requestedQuantity) {
                $name = $product?->name ?? (string) $productId;

                throw new InsufficientStockException("Stock insuficiente para el producto '{$name}'.");
            }

            $product->decrement('stock', $requestedQuantity);

            $sale->items()->create([
                'product_id' => $product->id,
                'quantity' => $requestedQuantity,
                'unit_price' => $unitPrice,
                'total_price' => round($unitPrice * $requestedQuantity, 2),
            ]);
        });
    }

    private function releaseItem(SaleItem $saleItem): void
    {
        $productId = (int) $saleItem->product_id;
        $quantity = (int) $saleItem->quantity;

        try {
            Cache::lock('product_lock:'.$productId, 10)->block(5, function () use ($productId, $quantity, $saleItem): void {
                Product::query()->whereKey($productId)->increment('stock', $quantity);
                $saleItem->delete();
            });
        } catch (LockTimeoutException) {
            Log::error('Could not release reserved stock after a failed order.', [
                'sale_item_id' => $saleItem->id,
                'product_id' => $productId,
                'quantity' => $quantity,
            ]);
        }
    }
}
