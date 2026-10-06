<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Events\OrderPlaced;
use App\Http\Controllers\Controller;
use App\Http\Resources\SaleResource;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SaleController extends Controller
{
    private const TAX_RATE = 0.21;

    public function index(): AnonymousResourceCollection
    {
        $sales = Sale::with(['customer', 'user', 'items.product'])
            ->latest()
            ->paginate(15);

        return SaleResource::collection($sales)->additional([
            'success' => true,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
        ]);

        $userId = $request->user()?->id;

        if ($userId === null) {
            return response()->json([
                'message' => 'No se pudo determinar el vendedor: autentícate con un token válido.',
            ], 422);
        }

        $subtotal = 0.0;

        foreach ($validated['items'] as $item) {
            $subtotal += round((float) $item['unit_price'] * (int) $item['quantity'], 2);
        }

        $subtotal = round($subtotal, 2);
        $tax = round($subtotal * self::TAX_RATE, 2);
        $total = round($subtotal + $tax, 2);
        $uuid = (string) Str::uuid();

        $sale = Sale::create([
            'uuid' => $uuid,
            'customer_id' => $validated['customer_id'],
            'user_id' => $userId,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
            'status' => 'PENDING',
        ]);

        OrderPlaced::dispatch($sale, $validated['items']);

        return response()->json([
            'message' => 'Order received and is being processed.',
            'order_uuid' => $sale->uuid,
            'status' => 'PENDING',
            'status_url' => '/api/sales/uuid/'.$sale->uuid,
        ], 202);
    }

    public function show(Sale $sale): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new SaleResource($sale->load(['customer', 'user', 'items.product'])),
        ]);
    }

    public function statusByUuid(string $uuid): JsonResponse
    {
        $sale = Sale::query()->where('uuid', $uuid)->first();

        if ($sale === null) {
            throw (new ModelNotFoundException)->setModel(Sale::class, [$uuid]);
        }

        return response()->json([
            'order_uuid' => $sale->uuid,
            'status' => $sale->status,
            'status_url' => '/api/sales/uuid/'.$sale->uuid,
            'data' => new SaleResource($sale->load(['customer', 'user', 'items.product'])),
        ]);
    }

    public function cancel(string $id): JsonResponse
    {
        $sale = Sale::query()->where('uuid', $id)->first();

        if ($sale === null) {
            throw (new ModelNotFoundException)->setModel(Sale::class, [$id]);
        }

        Gate::authorize('delete', $sale);

        if ($sale->status === 'CANCELLED') {
            return response()->json([
                'message' => 'Esta venta ya se encuentra cancelada.',
            ], 409);
        }

        if ($sale->status !== 'COMPLETED') {
            return response()->json([
                'message' => 'Solo se pueden cancelar ventas en estado COMPLETED.',
            ], 409);
        }

        $items = $sale->items->sortBy('product_id')->values();
        $locks = [];

        try {
            foreach ($items as $item) {
                $lock = Cache::lock('product_lock:'.$item->product_id, 10);
                $lock->block(5);
                $locks[] = $lock;
            }

            DB::transaction(function () use ($sale, $items): void {
                foreach ($items as $item) {
                    Product::query()
                        ->whereKey($item->product_id)
                        ->lockForUpdate()
                        ->increment('stock', (int) $item->quantity);
                }

                $sale->update(['status' => 'CANCELLED']);
            });
        } catch (LockTimeoutException) {
            return response()->json([
                'message' => 'No se pudo bloquear el inventario para cancelar la venta. Reintenta en unos segundos.',
            ], 409);
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }

        return response()->json([
            'message' => 'Venta cancelada correctamente. El stock de los productos fue restituido.',
            'order_uuid' => $sale->uuid,
            'status' => 'CANCELLED',
            'data' => new SaleResource($sale->fresh(['customer', 'user', 'items.product'])),
        ]);
    }
}
