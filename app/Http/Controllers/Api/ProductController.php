<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    // 1. GET /api/products -> Obtener todos los productos
    public function index()
    {
        // Traemos los productos junto con la categoría a la que pertenecen
        $products = Product::with('category')->get();

        return response()->json([
            'success' => true,
            'data' => $products
        ], 200);
    }

    // 2. POST /api/products -> Crear un nuevo producto
    public function store(Request $request)
    {
        // Validamos que los datos que envía el cliente sean correctos
        $validated = $request->validate([
            'category_id' => 'nullable|exists:categories,id',
            'sku'         => 'required|string|unique:products,sku',
            'name'        => 'required|string|max:255',
            'price'       => 'required|numeric|min:0',
            'stock'       => 'required|integer|min:0',
            'is_active'   => 'boolean'
        ]);

        $product = Product::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Producto creado con éxito',
            'data'    => $product
        ], 201); // 201 Created
    }

    // 3. GET /api/products/{id} -> Obtener un solo producto por ID
    public function show(Product $product)
    {
        return response()->json([
            'success' => true,
            'data'    => $product->load('category')
        ], 200);
    }

    // 4. PUT/PATCH /api/products/{id} -> Actualizar un producto
    public function update(Request $request, Product $product)
    {
        $validated = $request->validate([
            'category_id' => 'nullable|exists:categories,id',
            'sku'         => 'sometimes|required|string|unique:products,sku,' . $product->id,
            'name'        => 'sometimes|required|string|max:255',
            'price'       => 'sometimes|required|numeric|min:0',
            'stock'       => 'sometimes|required|integer|min:0',
            'is_active'   => 'boolean'
        ]);

        $product->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Producto actualizado correctamente',
            'data'    => $product
        ], 200);
    }

    // 5. DELETE /api/products/{id} -> Eliminar un producto
    public function destroy(Product $product)
    {
        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Producto eliminado correctamente'
        ], 200);
    }
}
