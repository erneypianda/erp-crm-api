<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    // 1. GET /api/categories -> Obtener todas las categorías
    public function index()
    {
        // Incluimos el conteo de productos asociados a cada categoría
        $categories = Category::withCount('products')->get();

        return response()->json([
            'success' => true,
            'data' => $categories
        ], 200);
    }

    // 2. POST /api/categories -> Crear una nueva categoría
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255|unique:categories,name',
            'description' => 'nullable|string',
        ]);

        $category = Category::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Categoría creada con éxito',
            'data'    => $category
        ], 201); // 201 Created
    }

    // 3. GET /api/categories/{id} -> Obtener una sola categoría por ID
    public function show(Category $category)
    {
        return response()->json([
            'success' => true,
            'data'    => $category->load('products')
        ], 200);
    }

    // 4. PUT/PATCH /api/categories/{id} -> Actualizar una categoría
    public function update(Request $request, Category $category)
    {
        $validated = $request->validate([
            'name'        => 'sometimes|required|string|max:255|unique:categories,name,' . $category->id,
            'description' => 'nullable|string',
        ]);

        $category->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Categoría actualizada correctamente',
            'data'    => $category
        ], 200);
    }

    // 5. DELETE /api/categories/{id} -> Eliminar una categoría
    public function destroy(Category $category)
    {
        // Los productos asociados quedan con category_id en null (ver migración: nullOnDelete)
        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Categoría eliminada correctamente'
        ], 200);
    }
}
