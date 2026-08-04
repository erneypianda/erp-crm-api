# ERP/CRM API

API REST construida con **Laravel 12** para la gestión de un sistema ERP/CRM enfocado en ventas: clientes, categorías, productos y transacciones de venta.

## 🚀 Stack tecnológico

- **PHP** ^8.2
- **Laravel Framework** ^12.0
- **Laravel Sanctum** ^4.0 (autenticación por tokens API)
- **MySQL** (base de datos relacional)
- **PHPUnit** (testing)

## 📦 Modelo de dominio

| Entidad | Descripción |
|---|---|
| `User` | Usuarios del sistema (autenticación vía Sanctum) |
| `Customer` | Clientes: nombre, email, teléfono, identificación fiscal (`tax_id`), dirección |
| `Category` | Categorías de productos |
| `Product` | Productos: SKU, nombre, precio, stock, estado (`is_active`), relación con `Category` |
| `Sale` | Ventas: cliente, vendedor, subtotal, impuestos, total, estado |
| `SaleItem` | Líneas de detalle de cada venta (producto, cantidad, precio unitario, total) |

## ⚙️ Instalación

```bash
# 1. Clonar el repositorio
git clone https://github.com/<tu-usuario>/erp-crm-api.git
cd erp-crm-api

# 2. Instalar dependencias
composer install

# 3. Configurar entorno
cp .env.example .env
php artisan key:generate

# 4. Configurar la base de datos en .env (DB_CONNECTION, DB_HOST, DB_DATABASE, etc.)

# 5. Ejecutar migraciones y poblar datos de prueba
php artisan migrate:fresh --seed

# 6. Levantar el servidor local
php artisan serve
```

## 🌱 Datos de prueba (seeders)

El `DatabaseSeeder` crea automáticamente:

- 1 usuario administrador (`admin@erp.com` / `password`)
- 5 categorías con 4 productos cada una (20 productos en total)
- 10 clientes de prueba

## 🧪 Testing

```bash
php artisan test
```

## 📌 Estado del proyecto

Proyecto en desarrollo activo. Próximos pasos:

- [ ] Endpoints API completos para `Customer`, `Category` y `Sale` (`Product` ya implementado)
- [ ] Registro de rutas `apiResource` versionadas (`/api/v1/...`)
- [ ] Form Requests y API Resources para respuestas consistentes
- [ ] Tests de feature por endpoint

## 📄 Licencia

Este proyecto es privado/propietario salvo que se indique lo contrario.
