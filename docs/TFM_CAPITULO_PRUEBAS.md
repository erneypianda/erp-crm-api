# Evaluación experimental del escenario *Flash Sale*

El presente capítulo describe el diseño, los criterios de aceptación y el marco de interpretación de la evaluación de rendimiento de la API de ventas. El objeto de medida es la ingesta orientada a eventos implementada sobre Laravel, colas Redis y cerrojos atómicos `Cache::lock`, ejercitada mediante Grafana k6 a través del guion `tests/k6/sales_stress_test.js`.

Las tablas cuantitativas se entregan como plantilla. Cada celda marcada como «—» debe completarse con el resumen que emite k6 al finalizar la ejecución. No se consignan en este capítulo valores de latencia, throughput ni tasas de error que no procedan de esa ejecución. La discusión arquitectónica distingue, por tanto, el comportamiento previsto por el diseño del resultado medido.

Para obtener el fichero de origen de las tablas se recomienda:

```bash
k6 run --summary-export=docs/k6-summary.json tests/k6/sales_stress_test.js
```

k6 termina con código de salida 99 cuando algún *threshold* no se cumple. Ese código constituye el dictamen automático del experimento y debe registrarse junto con la fecha, la versión de k6, el número de *workers* de cola y la URL objeto de la prueba (`BASE_URL`).

## 1. Planificación y diseño del escenario de pruebas

### 1.1. Justificación del perfil de carga

El escenario reproduce una venta relámpago (*flash sale*): un intervalo breve en el que la demanda de un catálogo reducido crece por encima de la capacidad de reposición del inventario. El interés experimental no reside en una carga estacionaria indefinida, sino en la respuesta del plano de ingesta HTTP cuando la concurrencia aparece, se sostiene y desaparece. Por ello el ejecutor seleccionado es `ramping-vus`, que varía el número de usuarios virtuales (VU) a lo largo de etapas sucesivas.

El perfil nominal comprende cuatro fases. Entre la meseta y el pico se insertan dos transiciones de 10 s. Sin esas transiciones, k6 interpolaría el salto de 50 a 200 VU, y el de 200 a 500 VU, a lo largo de toda la etapa siguiente, y la meseta dejaría de ser un régimen estacionario. Las transiciones aíslan el régimen que se desea medir.

| Fase | Duración | Objetivo | Régimen que se pretende observar |
|---|---:|---:|---|
| 1. Ramp-up | 30 s | 0 → 50 VU | Arranque en frío: establecimiento de conexiones, caché de opcode y primeras reservas |
| Transición A | 10 s | 50 → 200 VU | Paso a la meseta, excluido del análisis estacionario |
| 2. Meseta | 60 s | 200 VU | Capacidad sostenida de la ingesta bajo concurrencia alta y estable |
| Transición B | 10 s | 200 → 500 VU | Paso al pico, excluido del análisis estacionario |
| 3. Pico | 30 s | 500 VU | Saturación de corta duración y absorción del exceso por la cola |
| 4. Ramp-down | 20 s | 500 → 0 VU | Drenaje de la cola y ausencia de errores tardíos al cesar la llegada |

La duración programada de las etapas es de 160 s. A ese intervalo se añade el cierre ordenado `gracefulRampDown` de 20 s, durante el cual las iteraciones ya iniciadas pueden terminar el polling. El experimento completo queda, por tanto, en el entorno de los tres minutos, un horizonte coherente con un pico comercial y suficiente para distinguir la latencia de aceptación de la latencia de liquidación del inventario.

Cada VU ejecuta un ciclo cerrado: una petición de ingesta y, un segundo después, una consulta de estado. La pausa de 1 s (`sleep(1)`) modela a un cliente que no bloquea el hilo de compra a la espera del *worker*, pero que comprueba el resultado con una demora corta. Bajo 500 VU esa demora puede ser insuficiente para vaciar la cola; el indicador `orders_pending` cuantifica precisamente ese desfase.

La carga de negocio de cada iteración es deliberadamente homogénea: un único ítem, cantidad 1 y precio unitario 10. La homogeneidad reduce la varianza atribuible al tamaño del pedido y concentra la contención en el conjunto `PRODUCT_IDS`. Con el valor por defecto (`1,2,3,4`), centenares de VU compiten por cuatro productos. Esa es la condición en la que el cerrojo `product_lock:{id}` y el agotamiento de stock dejan de ser casos marginales.

### 1.2. Mitigación de falsos positivos en la autenticación

Las rutas de ventas se protegen con el middleware `auth:sanctum`. El inicio de sesión, `POST /api/login`, aplica además el limitador `throttle:5,1`: cinco peticiones por minuto para el cliente que las origina. Si cada una de las 500 iteraciones concurrentes solicitara un token, la mayoría recibiría `429 Too Many Requests`. k6 contabiliza ese código dentro de `http_req_failed`. El umbral de error del experimento se violaría por el limitador de credenciales, no por la ingesta de pedidos.

El guion evita ese falso positivo mediante el ciclo de vida de k6. La función `setup()` se ejecuta una sola vez, antes de crear los VU. Obtiene un token de Sanctum —o reutiliza la variable de entorno `TOKEN`— y lo entrega a todas las iteraciones. El coste de autenticación queda fuera de la muestra de ingesta, etiquetada como `endpoint:ingest`. De este modo, la tasa de fallo HTTP describe el plano de ventas y no el plano de identidad.

Esta decisión tiene una lectura de validez interna. El token compartido es un secreto de prueba de larga duración durante el experimento; no pretende caracterizar el rendimiento de `POST /api/login`. Cualquier conclusión sobre la autenticación exigiría un escenario distinto, con presupuesto propio de peticiones y sin mezclarlo con el percentil de aceptación de pedidos.

### 1.3. Contrato de prueba

El ciclo de cada VU reproduce el contrato asíncrono de la API.

1. `POST /api/sales` con `customer_id`, `product_id`, `quantity` y `unit_price`. La respuesta de éxito es HTTP 202 Accepted e incluye `order_uuid`, `status` igual a `PENDING` y `status_url`.
2. Pausa de 1 s.
3. `GET /api/sales/uuid/{order_uuid}`. La respuesta de éxito es HTTP 200 e informa si la reserva ha terminado en `COMPLETED` o en `FAILED`, o si continúa en `PENDING`.

El identificador utilizado en el polling es el UUID versión 4 devuelto por la ingesta. El identificador autonumérico de la tabla `sales` no forma parte del contrato público y no interviene en el guion.

Un código 500 en cualquiera de las dos peticiones se considera fallo de resiliencia. Un código 202 seguido de un estado de dominio `FAILED` no lo es: la plataforma ha aceptado la orden y el dominio ha rechazado la reserva. La separación entre ambos planos es el objeto de las métricas de la sección 2.

## 2. Definición de criterios de éxito

Los criterios se implementan como *thresholds* de k6 y como comprobaciones por respuesta. El percentil 95, denotado $P_{95}$, es el valor por debajo del cual se sitúa el 95 % de las observaciones de la muestra. Se adopta $P_{95}$, y no la media, porque la media oculta la cola de las peticiones que el usuario percibe durante el pico. $P_{99}$ se registra como indicador de cola extrema, aunque no constituye por sí solo un umbral de aceptación en este experimento.

### 2.1. Latencia de ingesta

La métrica propia `ingest_duration` acumula `timings.duration` de cada `POST /api/sales`. El umbral de aceptación es:

$$P_{95}(\text{ingest\_duration}) < 100\ \text{ms}.$$

Además, cada respuesta se comprueba de forma individual: el estado HTTP debe ser exactamente 202 y la duración de esa observación debe ser inferior a 100 ms. La comprobación individual alimenta la tasa de *checks*; el dictamen del experimento lo determina el percentil, que tolera una cola reducida de respuestas más lentas sin declarar fallida toda la prueba por una sola observación.

### 2.2. Latencia global del POST

Sobre las mismas peticiones, filtradas por la etiqueta `endpoint:ingest`, se exige:

$$P_{95}\big(http\_req\_duration\{\text{endpoint:ingest}\}\big) < 200\ \text{ms}.$$

El doble umbral (100 ms sobre la tendencia propia y 200 ms sobre la métrica HTTP etiquetada) separa el objetivo de diseño de la ingesta de un presupuesto más holgado que incluye la variabilidad del cliente k6 y de la pila de red. El polling no entra en ninguno de los dos umbrales: su latencia depende del estado ya persistido y no del camino crítico de aceptación.

### 2.3. Tasa de error HTTP

$$http\_req\_failed < 0{,}01.$$

En k6, `http_req_failed` es la razón entre peticiones fallidas y peticiones emitidas. Se consideran fallidas las respuestas con estado igual o superior a 400, los cierres de conexión y los tiempos de espera agotados. El límite del 1 % admite una fracción marginal de errores de transporte durante el pico de 500 VU y rechaza una degradación sistemática.

Conviene fijar qué no incrementa este indicador. Una orden que termina en `FAILED` por stock insuficiente responde 202 en la ingesta y 200 en el polling. Una orden aún `PENDING` también responde 200. Esos resultados pertenecen a las métricas de dominio. Sí incrementan `http_req_failed`, entre otros, un 422 por un `PRODUCT_IDS` inexistente, un 401 por un token inválido, un 429 y cualquier 500. Por ello los identificadores de producto y de cliente deben existir en la base de datos antes de interpretar la tasa de error como una propiedad de la arquitectura.

### 2.4. Métricas de dominio

Tres contadores, incrementados únicamente tras el `GET` de polling, describen el resultado de negocio un segundo después de la aceptación:

| Contador | Condición de incremento | Lectura arquitectónica |
|---|---|---|
| `orders_completed` | `status = COMPLETED` | La reserva descontó stock, persistió el `SaleItem` y cerró la orden dentro de la ventana de 1 s |
| `orders_failed` | `status = FAILED` | El dominio rechazó la reserva (stock insuficiente o fallo de la unidad de trabajo) sin romper el contrato HTTP |
| `orders_pending` | `status = PENDING` | Al cabo de 1 s el *worker* no ha liquidado la orden: la cola absorbe el pico y el cliente observa consistencia eventual |

La suma de los tres contadores no tiene por qué igualar el número de POST con respuesta 202 si alguna consulta de estado no devolvió 200. La diferencia debe investigarse como fallo de lectura, no como fallo de reserva.

> **Criterio de lectura del pico.** Un crecimiento de `orders_pending` durante los 500 VU, acompañado de un $P_{95}$ de ingesta dentro del umbral y de `http_req_failed` inferior al 1 %, se interpreta como allanado del pico (*peak shaving*): el plano HTTP permanece estable mientras el plano de inventario drena la cola. Un crecimiento de `http_req_failed` o de respuestas 500 se interpreta, por el contrario, como saturación del plano de aceptación.

## 3. Análisis e interpretación de resultados cuantitativos

Esta sección fija el procedimiento de explotación del resumen de k6. Las cifras se incorporarán después de la ejecución. Hasta entonces, la tabla documenta la definición operativa de cada indicador y la fuente dentro del JSON exportado.

### 3.1. Condiciones de la corrida

| Parámetro | Valor registrado |
|---|---|
| Fecha y hora | — |
| Versión de k6 | — |
| `BASE_URL` | — |
| *Workers* de la cola `inventory` | — |
| Motor relacional y tamaño del pool | — |
| `PRODUCT_IDS` / `CUSTOMER_ID` | — |
| Código de salida de k6 (0 = umbrales cumplidos; 99 = umbral incumplido) | — |

### 3.2. Throughput de la ingesta

El throughput de aceptación se define como el número de respuestas HTTP 202 del `POST /api/sales` dividido por la duración efectiva del experimento, expresado en peticiones por segundo (RPS). En el resumen de k6 puede obtenerse a partir de `http_reqs` filtrado por `endpoint:ingest`, dividiendo el recuento por la duración en segundos. No debe utilizarse el throughput agregado de todas las peticiones, porque cada iteración emite también un GET y el valor agregado aproximadamente duplicaría la capacidad de ingesta.

| Indicador | Ingesta `POST /api/sales` | Polling `GET /api/sales/uuid/{uuid}` |
|---|---:|---:|
| Peticiones emitidas | — | — |
| Respuestas 202 o 200, según el verbo | — | — |
| Throughput medio (RPS) | — | — |
| Throughput máximo observado en el pico (RPS) | — | — |

El throughput máximo durante los 30 s de pico es el dato que debe contrastarse con la meseta de 200 VU. Si el RPS de ingesta crece al pasar de 200 a 500 VU mientras $P_{95}$ permanece por debajo de 100 ms, la ingesta aún no ha saturado. Si el RPS se estanca y $P_{95}$ supera el umbral, el plano HTTP ha alcanzado su techo y la cola, por sí sola, ya no oculta ese techo: lo oculta solo mientras la aceptación sigue siendo breve.

### 3.3. Comparativa de latencia

Todas las latencias se expresan en milisegundos (ms). $P_{50}$ es la mediana. $P_{90}$, $P_{95}$ y $P_{99}$ describen la cola derecha. La columna «Umbral» reproduce el criterio de la sección 2; la columna «Dictamen» se completa con *cumple* o *no cumple*.

| Métrica | $P_{50}$ (ms) | $P_{90}$ (ms) | $P_{95}$ (ms) | $P_{99}$ (ms) | Máximo (ms) | Umbral sobre $P_{95}$ | Dictamen |
|---|---:|---:|---:|---:|---:|---|---|
| `ingest_duration` | — | — | — | — | — | $< 100$ ms | — |
| `http_req_duration` de `endpoint:ingest` | — | — | — | — | — | $< 200$ ms | — |
| `http_req_duration` de `endpoint:poll` | — | — | — | — | — | Sin umbral de aceptación | — |
| `http_req_failed` (razón, no ms) | — | — | — | — | — | $< 1\ \%$ | — |

En el JSON de k6, los percentiles de una tendencia figuran en `metrics.<nombre>.values` con las claves `med` o `p(50)`, `p(90)`, `p(95)`, `p(99)` y `max`, según la configuración `summaryTrendStats` de la versión utilizada. Si el resumen por defecto no muestra $P_{90}$, la corrida puede repetirse con:

```bash
k6 run --summary-trend-stats="med,p(90),p(95),p(99),max" --summary-export=docs/k6-summary.json tests/k6/sales_stress_test.js
```

### 3.4. Cerrojos atómicos y contención por producto

La reserva de cada ítem se ejecuta dentro de `Cache::lock('product_lock:' + productId, 10)->block(5, ...)`. El primer argumento es la clave, el segundo es el tiempo de vida del cerrojo (10 s) y `block(5)` es la espera máxima para adquirirlo (5 s). La sección crítica, ya bajo el cerrojo, bloquea la fila del producto con `lockForUpdate()`, comprueba el stock, lo descuenta y crea el `SaleItem`. Todo ello participa de una transacción relacional que, si la reserva no puede completarse, revierte los descuentos antes de marcar la orden como `FAILED`.

Cuando muchos VU seleccionan el mismo `product_id`, los *workers* se serializan sobre esa clave. El efecto esperado es el siguiente.

| Fenómeno | Efecto previsto sobre la medida | Dónde observarlo |
|---|---|---|
| Varios pedidos concurrentes sobre cuatro SKU | La cola de la clave Redis crece; la ingesta HTTP no espera al cerrojo | $P_{95}$ de `ingest_duration` estable y `orders_pending` elevado en el pico |
| Stock agotado bajo el cerrojo | La transacción revierte y la orden pasa a `FAILED` | Incremento de `orders_failed` sin incremento proporcional de `http_req_failed` |
| Espera de cerrojo superior a 5 s durante una cancelación | La API responde 409 y no altera el stock | Colección funcional (`tests/api_samples.md`), no el ciclo de k6 |
| Cerrojo liberado y fila aún bloqueada por la transacción | El `lockForUpdate()` impide que otro *worker* decida con una lectura obsoleta | Ausencia de stock negativo en `products.stock` al terminar la prueba |

La última fila debe verificarse con una consulta posterior al experimento, no con k6:

```sql
SELECT id, sku, stock FROM products WHERE stock < 0;
```

El resultado esperado de esa consulta es un conjunto vacío. Un stock negativo indicaría que la comprobación y el descuento no se ejecutaron como una sola sección crítica.

| Comprobación posterior a la corrida | Resultado |
|---|---|
| Filas de `products` con `stock < 0` | — |
| Órdenes `COMPLETED` cuyo stock descontado no coincide con la suma de `sale_items.quantity` | — |
| Órdenes `FAILED` con `sale_items` residuales | — |
| Valor final de `orders_completed` | — |
| Valor final de `orders_failed` | — |
| Valor final de `orders_pending` | — |

### 3.5. Desacoplamiento HTTP frente al modelo síncrono

El modelo anterior resolvía `POST /api/sales` dentro de una transacción relacional con `lockForUpdate()`: el hilo HTTP no respondía hasta haber comprobado el stock, insertado el detalle y descontado el inventario. El modelo evaluado persiste la orden en `PENDING`, publica `OrderPlaced` y responde 202. La sección crítica queda en el *worker* de la cola `inventory`.

La comparación que sigue es de diseño. La columna de cifras del modelo síncrono solo debe rellenarse si se ejecuta una línea base equivalente, con el mismo perfil de VU, el mismo catálogo y el mismo *hardware*. En ausencia de esa corrida, la columna permanece en blanco y no se infieren milisegundos.

| Dimensión | Modelo síncrono (`DB::transaction` y `lockForUpdate` en el hilo HTTP) | Modelo orientado a eventos (202 + cola + cerrojo Redis) |
|---|---|---|
| Momento en que el cliente recibe respuesta | Al confirmar o rechazar el stock | Al aceptar la orden (`PENDING`) |
| Contrato HTTP de éxito | 201 Created tras la reserva | 202 Accepted antes de la reserva |
| Conexión relacional ocupada por la petición web | Durante toda la sección crítica y la espera de fila | Durante el `INSERT` de la cabecera y la publicación del job |
| Efecto de la contención sobre el percentil HTTP | La espera del cerrojo de fila se suma a la latencia del POST | La espera del cerrojo se suma a la latencia de liquidación, visible como `PENDING` o como tiempo hasta `COMPLETED` |
| Fallo de negocio por stock | Error HTTP en el mismo POST | Estado `FAILED` en un GET posterior |
| Riesgo que se traslada | Saturación de trabajadores PHP y del pool de conexiones | Acumulación de la cola y ventana de consistencia eventual |
| $P_{95}$ del POST en la línea base (ms) | — | — |
| $P_{95}$ del POST en el modelo de eventos (ms) | — | — |

La hipótesis que la corrida permite contrastar es la siguiente: bajo el mismo pico, el $P_{95}$ de la ingesta del modelo de eventos permanece por debajo de 100 ms aunque una fracción creciente de órdenes siga en `PENDING` al segundo de vida. Si ambos percentiles crecen a la vez, el cuello de botella está en la aceptación (persistencia de la cabecera o publicación en Redis) y el desacoplamiento no está aislando al cliente de ese cuello.

## 4. Discusión de resiliencia y consistencia eventual

### 4.1. Allanado del pico y pool de conexiones

En un modelo síncrono, cada VU que espera un `lockForUpdate` retiene un trabajador del servidor de aplicaciones y una conexión del pool relacional. Con 500 VU simultáneos, ese número puede superar el tamaño del pool. Las peticiones nuevas no fallan entonces por una regla de negocio, sino porque no obtienen conexión: el síntoma típico es un aumento de tiempos de espera y de errores 500, es decir, un incremento de `http_req_failed`.

La arquitectura evaluada acorta esa retención en el plano HTTP. El proceso que atiende `POST /api/sales` valida el cuerpo, calcula subtotal, impuesto al 21 % y total, inserta la cabecera en estado `PENDING` y publica el evento. La respuesta 202 se emite sin recorrer el catálogo bajo cerrojo. El exceso de demanda se acumula en la cola Redis `inventory`, cuyo consumidor es un conjunto acotado de *workers*. Ese conjunto fija cuántas secciones críticas —y, por tanto, cuántas conexiones de escritura— existen a la vez, con independencia de que los VU pasen de 200 a 500.

El allanado del pico no elimina el trabajo: lo desplaza en el tiempo. Durante la fase 3 la velocidad de llegada puede superar a la de liquidación. Las órdenes aceptadas permanecen en `PENDING` y el contador `orders_pending` crece. Durante el ramp-down la llegada cesa y los *workers* drenan la cola; una consulta posterior al cierre de k6 debería mostrar una reducción de `PENDING` hacia `COMPLETED` o `FAILED`. Documentar el número de órdenes todavía `PENDING` cinco minutos después del ramp-down permite separar un retardo de cola de un *worker* detenido.

La consistencia que observa el cliente es eventual respecto del stock. Entre el 202 y el cierre de la reserva, dos lecturas del mismo UUID pueden diferir, y el stock publicado en catálogo puede aún no reflejar la intención de compra. El diseño lo hace explícito mediante el estado `PENDING` y mediante `status_url`. No se promete al cliente un 201 con stock ya descontado.

El aislamiento de la sección crítica se apoya en dos mecanismos complementarios. El cerrojo Redis serializa a los *workers* que intentan reservar el mismo producto, de modo que la espera de contención no se implementa como una acumulación ilimitada de transacciones abiertas. El `lockForUpdate()` cubre el intervalo que media entre la liberación del cerrojo Redis y la confirmación de la transacción, e impide que una segunda transacción decida con una lectura anterior al `COMMIT`. La comprobación posterior de stock no negativo, indicada en la sección 3.4, es la prueba de que esa composición se ha mantenido durante el pico.

### 4.2. Errores de negocio y código 409

El experimento de carga no utiliza el código 409 como indicador de éxito o de fallo de la ingesta. Una reserva rechazada por el *worker* no se comunica con 409: el POST ya respondió 202 y el polling observa `FAILED`. Reservar el 409 para esa situación habría obligado al cliente a interpretar un conflicto en una petición que ya había sido aceptada, y habría mezclado en `http_req_failed` un resultado de dominio con un fallo de plataforma.

El código 409 se reserva para conflictos que el hilo HTTP puede resolver sin dejar una orden a medias y sin devolver 500.

| Situación | Respuesta | Efecto sobre el stock |
|---|---|---|
| Cancelación de una orden que no está `COMPLETED` (`PENDING` o `FAILED`) | 409, mensaje de estado no cancelable | Nulo |
| Cancelación repetida de una orden `CANCELLED` | 409, mensaje de orden ya cancelada | Nulo |
| `block(5)` agota la espera del cerrojo Redis durante la cancelación | 409, mensaje de reintento | Nulo: no se confirma la reposición |
| `InsufficientStockException` si la excepción alcanzara el hilo HTTP | 409 con el mensaje de dominio | La transacción de reserva no queda confirmada |

En los tres primeros casos el 409 protege un invariante: solo se repone el stock de una venta efectivamente completada, y solo se hace cuando el cerrojo del producto ha podido adquirirse. Convertir la espera agotada en 500 ocultaría un conflicto de concurrencia bajo un fallo interno y dispararía reintentos ciegos del cliente. El 409, en cambio, es reintentable de forma consciente en el caso del cerrojo y terminal en el caso de una cancelación inválida.

La misma distinción debe aplicarse al leer el resumen de k6. `orders_failed` alto con `http_req_failed` por debajo del 1 % y sin filas de stock negativo describe un sistema que ha degradado el negocio —no todas las intenciones de compra pueden servirse cuando el inventario es finito— y ha preservado la plataforma. `http_req_failed` por encima del 1 %, o cualquier proporción relevante de respuestas 500, describe lo contrario: el pico ha atravesado el plano de aceptación. Solo el primer resultado apoya la hipótesis de resiliencia de la arquitectura orientada a eventos.

## Referencia operativa del guion

| Elemento | Localización |
|---|---|
| Escenario de carga y umbrales | `tests/k6/sales_stress_test.js` |
| Casos funcionales, incluidos 404 y 409 | `tests/api_samples.md` |
| Ingesta y polling | `POST /api/sales`, `GET /api/sales/uuid/{uuid}` |
| Cola de liquidación | `inventory` (`php artisan queue:work redis --queue=inventory`) |
| Cerrojo | `product_lock:{productId}`, TTL 10 s, espera máxima 5 s |
