# Pruebas HTTP del módulo de ventas

Colección funcional de la API de ingesta asíncrona. Todas las rutas de ventas exigen `Authorization: Bearer <token>` (Sanctum). La cancelación, además, exige un usuario con rol `admin`.

Sustituye `BASE_URL` (por defecto `http://localhost:8000`) y los identificadores por datos reales del seeder (`admin@erp.com` / `password`, clientes y productos creados por `DatabaseSeeder`).

El worker tiene que estar activo para que el polling pase de `PENDING` a `COMPLETED` o `FAILED`:

```bash
php artisan queue:work redis --queue=inventory
```

## 0. Obtener el token

```bash
curl -s -X POST "%BASE_URL%/api/login" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"admin@erp.com\",\"password\":\"password\"}"
```

Respuesta esperada: `200 OK`.

```json
{
  "success": true,
  "message": "Inicio de sesión exitoso",
  "data": {
    "token": "1|token-de-sanctum",
    "user": {
      "email": "admin@erp.com",
      "role": "admin"
    }
  }
}
```

El login está limitado a 5 intentos por minuto. En la prueba de carga el token se obtiene una sola vez y se reutiliza.

## 1. Crear venta exitosa

`POST /api/sales`

Payload válido. Con `quantity = 2` y `unit_price = 10.00` el desglose persistido es subtotal `20.00`, IVA 21% `4.20` y total `24.20`. El hilo HTTP no descuenta stock.

```bash
curl -s -D - -X POST "%BASE_URL%/api/sales" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer %TOKEN%" \
  -d "{\"customer_id\":1,\"items\":[{\"product_id\":1,\"quantity\":2,\"unit_price\":10.00}]}"
```

Respuesta esperada: `202 Accepted`.

```json
{
  "message": "Order received and is being processed.",
  "order_uuid": "6f1c2a40-9c2e-4b1a-8d77-3a0e5b9c1d22",
  "status": "PENDING",
  "status_url": "/api/sales/uuid/6f1c2a40-9c2e-4b1a-8d77-3a0e5b9c1d22"
}
```

Guarda `order_uuid`. Es el único identificador público de la orden.

## 2. Polling de estado

`GET /api/sales/uuid/{uuid}`

Consulta por UUID v4 después de dejar correr el worker (en local, uno o dos segundos bastan si la cola está vacía).

```bash
curl -s -D - "%BASE_URL%/api/sales/uuid/%ORDER_UUID%" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer %TOKEN%"
```

Respuesta esperada: `200 OK` cuando la reserva termina bien. `subtotal`, `tax` y `total` viajan dentro de `data`.

```json
{
  "order_uuid": "6f1c2a40-9c2e-4b1a-8d77-3a0e5b9c1d22",
  "status": "COMPLETED",
  "status_url": "/api/sales/uuid/6f1c2a40-9c2e-4b1a-8d77-3a0e5b9c1d22",
  "data": {
    "uuid": "6f1c2a40-9c2e-4b1a-8d77-3a0e5b9c1d22",
    "subtotal": 20,
    "tax": 4.2,
    "total": 24.2,
    "status": "COMPLETED"
  }
}
```

Si el worker todavía no ha tomado el job, `status` sigue en `PENDING`. Eso no es un error HTTP.

## 3. Cancelar venta

`DELETE /api/sales/{uuid}`

Solo una venta `COMPLETED` y solo un administrador. La operación toma el lock `product_lock:{product_id}`, repone el stock y deja la orden en `CANCELLED`.

```bash
curl -s -D - -X DELETE "%BASE_URL%/api/sales/%ORDER_UUID%" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer %TOKEN%"
```

Respuesta esperada: `200 OK`.

```json
{
  "message": "Venta cancelada correctamente. El stock de los productos fue restituido.",
  "order_uuid": "6f1c2a40-9c2e-4b1a-8d77-3a0e5b9c1d22",
  "status": "CANCELLED"
}
```

Un usuario sin rol `admin` recibe `403 Forbidden`.

## 4. Conflicto de inventario o de lock

La falta de stock no se responde en el `POST`. La ingesta sigue siendo `202 Accepted` y el worker marca la orden como `FAILED`. El `409` es el conflicto que sí resuelve el hilo HTTP: cancelar una orden que no está `COMPLETED`, o no conseguir el lock de Redis al reponer stock.

### 4.1 Reserva rechazada por el worker

Repite el `POST` del escenario 1 pidiendo más unidades de las que hay en `products.stock`. Luego consulta el UUID.

Respuesta esperada del `POST`: `202 Accepted` con `status: "PENDING"`.

Respuesta esperada del `GET /api/sales/uuid/{uuid}`: `200 OK` con `status: "FAILED"`. El stock de los productos no queda descontado.

```json
{
  "order_uuid": "6f1c2a40-9c2e-4b1a-8d77-3a0e5b9c1d22",
  "status": "FAILED",
  "status_url": "/api/sales/uuid/6f1c2a40-9c2e-4b1a-8d77-3a0e5b9c1d22"
}
```

### 4.2 Cancelar una orden que no está COMPLETED

`DELETE` sobre una orden `PENDING` o `FAILED`.

```bash
curl -s -D - -X DELETE "%BASE_URL%/api/sales/%ORDER_UUID%" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer %TOKEN%"
```

Respuesta esperada: `409 Conflict`.

```json
{
  "message": "Solo se pueden cancelar ventas en estado COMPLETED."
}
```

Repetir el `DELETE` sobre una orden ya `CANCELLED` también responde `409`:

```json
{
  "message": "Esta venta ya se encuentra cancelada."
}
```

### 4.3 Lock de Redis no adquirido

Si `Cache::lock('product_lock:{id}', 10)->block(5)` agota la espera durante la cancelación, la API responde `409` y no cambia el estado ni el stock.

```json
{
  "message": "No se pudo bloquear el inventario para cancelar la venta. Reintenta en unos segundos."
}
```

Si `InsufficientStockException` llegara al hilo HTTP (por ejemplo con `QUEUE_CONNECTION=sync`), el manejador de excepciones también responde `409` con el mensaje de la excepción. Con colas Redis ese fallo queda en el escenario 4.1.

## 5. Recurso no encontrado

UUID v4 bien formado que no existe. Sirve tanto para el polling como para la cancelación.

```bash
curl -s -D - "%BASE_URL%/api/sales/uuid/00000000-0000-4000-8000-000000000000" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer %TOKEN%"
```

```bash
curl -s -D - -X DELETE "%BASE_URL%/api/sales/00000000-0000-4000-8000-000000000000" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer %TOKEN%"
```

Respuesta esperada: `404 Not Found`.

```json
{
  "message": "No query results for model [App\\Models\\Sale] 00000000-0000-4000-8000-000000000000"
}
```

Sin cabecera `Accept: application/json`, Laravel puede devolver el 404 en HTML. Las pruebas deben pedir JSON.
