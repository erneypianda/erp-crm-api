<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Crear un usuario de prueba para autenticación
       // En lugar de User::factory()->create([...]);
User::firstOrCreate(
    ['email' => 'admin@erp.com'], // Busca si ya existe este correo
    [
        'name' => 'Admin ERP',
        'password' => bcrypt('password'), // Asigna los datos si no existe
        'role' => 'admin',
    ]
);

        // 2. Crear 5 categorías y 20 productos repartidos entre ellas
        Category::factory(5)->create()->each(function ($category) {
            Product::factory(4)->create([
                'category_id' => $category->id,
            ]);
        });

        // 3. Crear 10 clientes
        Customer::factory(10)->create();
    }
}
