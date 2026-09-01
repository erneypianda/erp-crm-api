<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    // 1. GET /api/customers -> Obtener todos los clientes (paginado)
    public function index()
    {
        $customers = Customer::paginate(15);

        return CustomerResource::collection($customers)->additional([
            'success' => true,
        ]);
    }

    // 2. POST /api/customers -> Crear un nuevo cliente
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'email'   => 'required|email|unique:customers,email',
            'phone'   => 'nullable|string|max:20',
            'tax_id'  => 'nullable|string|max:20',
            'address' => 'nullable|string',
        ]);

        $customer = Customer::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Cliente creado con éxito',
            'data'    => new CustomerResource($customer)
        ], 201); // 201 Created
    }

    // 3. GET /api/customers/{id} -> Obtener un solo cliente por ID
    public function show(Customer $customer)
    {
        return response()->json([
            'success' => true,
            'data'    => new CustomerResource($customer->load('sales'))
        ], 200);
    }

    // 4. PUT/PATCH /api/customers/{id} -> Actualizar un cliente
    public function update(Request $request, Customer $customer)
    {
        $validated = $request->validate([
            'name'    => 'sometimes|required|string|max:255',
            'email'   => 'sometimes|required|email|unique:customers,email,' . $customer->id,
            'phone'   => 'nullable|string|max:20',
            'tax_id'  => 'nullable|string|max:20',
            'address' => 'nullable|string',
        ]);

        $customer->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Cliente actualizado correctamente',
            'data'    => new CustomerResource($customer)
        ], 200);
    }

    // 5. DELETE /api/customers/{id} -> Eliminar un cliente
    public function destroy(Customer $customer)
    {
        // Ojo: sales.customer_id tiene cascadeOnDelete(), por lo que sus ventas también se eliminarán
        $customer->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cliente eliminado correctamente'
        ], 200);
    }
}
