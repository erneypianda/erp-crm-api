<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Http\Resources\SaleResource;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SaleController extends Controller
{
    // Tasa de impuesto aplicada al subtotal de cada venta (21% IVA).
    private const TAX_RATE = 0.21;

    // 1. GET /api/sales -> Obtener todas las ventas (paginado)
    public function index()
    {
        $sales = Sale::with(['customer', 'user', 'items.product'])
            ->latest()
            ->paginate(15);

        return SaleResource::collection($sales)->additional([
            'success' => true,
        ]);
    }

    // 2. POST /api/sales -> Registrar una nueva venta
    public function store(Request $request)
    {
        // Validamos la estructura básica de la petición
        $validated = $request->validate([
            'customer_id'          => 'required|integer|exists:customers,id',
            'user_id'              => 'nullable|integer|exists:users,id',
            'items'                => 'required|array|min:1',
            'items.*.product_id'   => 'required|integer|exists:products,id',
            'items.*.quantity'     => 'required|integer|min:1',
        ]);

        // El vendedor viene del payload o, en su defecto, del usuario autenticado
        $userId = $validated['user_id'] ?? $request->user()?->id;

        if (! $userId) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo determinar el vendedor: envía "user_id" o autentícate con un token válido.',
            ], 422);
        }

        // Verificamos que todos los productos solicitados estén activos antes
        // de abrir la transacción (un producto inactivo no debe poder venderse).
        $productIds = array_column($validated['items'], 'product_id');
        $inactiveProducts = Product::whereIn('id', $productIds)
            ->where('is_active', false)
            ->pluck('name', 'id');

        if ($inactiveProducts->isNotEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Uno o más productos no están disponibles para la venta.',
                'errors'  => [
                    'items' => $inactiveProducts
                        ->map(fn ($name, $id) => "El producto '{$name}' (ID: {$id}) no está activo.")
                        ->values(),
                ],
            ], 422); // 422 Unprocessable Entity (error de validación)
        }

        try {
            $sale = DB::transaction(function () use ($validated, $userId) {
                $subtotal = 0;
                $itemsToCreate = [];

                foreach ($validated['items'] as $item) {
                    // Bloqueamos la fila del producto para evitar condiciones de carrera
                    // (dos ventas simultáneas descontando el mismo stock).
                    $product = Product::where('id', $item['product_id'])
                        ->lockForUpdate()
                        ->first();

                    if (! $product) {
                        throw new InsufficientStockException(
                            "El producto con ID {$item['product_id']} no existe."
                        );
                    }

                    if ($product->stock < $item['quantity']) {
                        throw new InsufficientStockException(
                            "Stock insuficiente para el producto '{$product->name}' (SKU: {$product->sku}). "
                            . "Disponible: {$product->stock}, solicitado: {$item['quantity']}."
                        );
                    }

                    $unitPrice  = $product->price;
                    $totalPrice = round($unitPrice * $item['quantity'], 2);
                    $subtotal  += $totalPrice;

                    $itemsToCreate[] = [
                        'product'     => $product,
                        'quantity'    => $item['quantity'],
                        'unit_price'  => $unitPrice,
                        'total_price' => $totalPrice,
                    ];
                }

                $tax   = round($subtotal * self::TAX_RATE, 2);
                $total = round($subtotal + $tax, 2);

                $sale = Sale::create([
                    'customer_id' => $validated['customer_id'],
                    'user_id'     => $userId,
                    'subtotal'    => $subtotal,
                    'tax'         => $tax,
                    'total'       => $total,
                    'status'      => 'completed',
                ]);

                foreach ($itemsToCreate as $data) {
                    $sale->items()->create([
                        'product_id'  => $data['product']->id,
                        'quantity'    => $data['quantity'],
                        'unit_price'  => $data['unit_price'],
                        'total_price' => $data['total_price'],
                    ]);

                    // Descontamos el inventario únicamente tras confirmar disponibilidad
                    $data['product']->decrement('stock', $data['quantity']);
                }

                return $sale;
            });
        } catch (InsufficientStockException $e) {
            // La transacción ya hizo rollback automáticamente al lanzar la excepción
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400); // 400 Bad Request
        }

        return response()->json([
            'success' => true,
            'message' => 'Venta registrada con éxito',
            'data'    => new SaleResource($sale->load(['customer', 'user', 'items.product'])),
        ], 201); // 201 Created
    }

    // 3. GET /api/sales/{id} -> Obtener una sola venta por ID
    public function show(Sale $sale)
    {
        return response()->json([
            'success' => true,
            'data'    => new SaleResource($sale->load(['customer', 'user', 'items.product']))
        ], 200);
    }

    // 4. DELETE /api/sales/{id} -> Cancelar una venta
    //
    // Por integridad y trazabilidad contable, las ventas NO se eliminan físicamente.
    // "destroy" cancela la venta: revierte el stock de cada producto vendido y
    // marca la venta como 'cancelled'.
    public function destroy(Sale $sale)
    {
        // Solo un administrador puede cancelar ventas (ver SalePolicy::delete)
        Gate::authorize('delete', $sale);

        if ($sale->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Esta venta ya se encuentra cancelada.',
            ], 409); // 409 Conflict
        }

        DB::transaction(function () use ($sale) {
            foreach ($sale->items as $item) {
                // Devolvemos el stock reservado por esta venta
                Product::where('id', $item->product_id)
                    ->lockForUpdate()
                    ->increment('stock', $item->quantity);
            }

            $sale->update(['status' => 'cancelled']);
        });

        return response()->json([
            'success' => true,
            'message' => 'Venta cancelada correctamente. El stock de los productos fue restituido.',
            'data'    => new SaleResource($sale->fresh(['customer', 'user', 'items.product'])),
        ], 200);
    }
}
