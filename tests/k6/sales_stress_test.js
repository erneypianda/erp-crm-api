/**
 * Flash sale contra la ingesta asíncrona de ventas.
 *
 * Requisitos locales:
 *   - API en marcha (php artisan serve)
 *   - Redis, migraciones y seeder
 *   - Worker: php artisan queue:work redis --queue=inventory
 *
 * El login de la API admite 5 intentos por minuto. Este script autentica
 * una sola vez en setup() y reutiliza el Bearer token en todos los VUs.
 *
 * Ejecución:
 *   k6 run tests/k6/sales_stress_test.js
 *
 * Variables de entorno opcionales:
 *   BASE_URL=http://localhost:8000
 *   TOKEN=<bearer>                  si se omite, setup() hace login
 *   LOGIN_EMAIL=admin@erp.com
 *   LOGIN_PASSWORD=password
 *   CUSTOMER_ID=1
 *   PRODUCT_IDS=1,2,3,4
 *
 * Criterios de éxito (thresholds; k6 sale con código 99 si no se cumplen):
 *   - ingest_duration p95 < 100 ms (POST /api/sales)
 *   - http_req_duration del POST p95 < 200 ms
 *   - http_req_failed < 1%
 *   - orders_completed y orders_failed cuentan el resultado del polling
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';

const ingestDuration = new Trend('ingest_duration', true);
const ordersCompleted = new Counter('orders_completed');
const ordersFailed = new Counter('orders_failed');
const ordersPending = new Counter('orders_pending');

const baseUrl = __ENV.BASE_URL || 'http://localhost:8000';
const customerId = Number(__ENV.CUSTOMER_ID || 1);
const productIds = (__ENV.PRODUCT_IDS || '1,2,3,4')
    .split(',')
    .map((id) => Number(id.trim()))
    .filter((id) => Number.isFinite(id) && id > 0);

if (productIds.length === 0) {
    throw new Error('PRODUCT_IDS no contiene identificadores numéricos.');
}

export const options = {
    scenarios: {
        flash_sale: {
            executor: 'ramping-vus',
            startVUs: 0,
            gracefulRampDown: '20s',
            stages: [
                // Fase 1. Subida de 0 a 50 VUs.
                { duration: '30s', target: 50 },
                // Transición corta para que la fase 2 sea una meseta, no una rampa.
                { duration: '10s', target: 200 },
                // Fase 2. Mantener 200 VUs durante 1 minuto.
                { duration: '1m', target: 200 },
                // Transición al pico.
                { duration: '10s', target: 500 },
                // Fase 3. Estrés a 500 VUs durante 30 segundos.
                { duration: '30s', target: 500 },
                // Fase 4. Enfriamiento a 0 VUs.
                { duration: '20s', target: 0 },
            ],
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        ingest_duration: ['p(95)<100'],
        'http_req_duration{endpoint:ingest}': ['p(95)<200'],
    },
};

export function setup() {
    if (__ENV.TOKEN) {
        return { token: __ENV.TOKEN };
    }

    const response = http.post(
        `${baseUrl}/api/login`,
        JSON.stringify({
            email: __ENV.LOGIN_EMAIL || 'admin@erp.com',
            password: __ENV.LOGIN_PASSWORD || 'password',
        }),
        {
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            tags: { endpoint: 'login' },
        },
    );

    if (response.status !== 200) {
        throw new Error(`Login de setup falló con HTTP ${response.status}: ${response.body}`);
    }

    return { token: response.json('data.token') };
}

export default function (data) {
    const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: `Bearer ${data.token}`,
    };

    const orderUuid = placeOrder(headers);

    if (orderUuid) {
        sleep(1);
        pollOrder(orderUuid, headers);
    }
}

function placeOrder(headers) {
    const productId = productIds[Math.floor(Math.random() * productIds.length)];
    const payload = JSON.stringify({
        customer_id: customerId,
        items: [
            {
                product_id: productId,
                quantity: 1,
                unit_price: 10,
            },
        ],
    });

    const response = http.post(`${baseUrl}/api/sales`, payload, {
        headers,
        tags: { endpoint: 'ingest' },
    });

    ingestDuration.add(response.timings.duration);

    check(response, {
        'ingest status is 202': (res) => res.status === 202,
        'ingest latency under 100ms': (res) => res.timings.duration < 100,
        'ingest body has order_uuid and PENDING': (res) => {
            if (res.status !== 202) {
                return false;
            }

            const body = res.json();

            return typeof body.order_uuid === 'string'
                && body.status === 'PENDING'
                && typeof body.status_url === 'string';
        },
    });

    if (response.status !== 202) {
        return null;
    }

    return response.json('order_uuid');
}

function pollOrder(orderUuid, headers) {
    const response = http.get(`${baseUrl}/api/sales/uuid/${orderUuid}`, {
        headers,
        tags: { endpoint: 'poll' },
    });

    const status = response.status === 200 ? response.json('status') : null;

    if (status === 'COMPLETED') {
        ordersCompleted.add(1);
    } else if (status === 'FAILED') {
        ordersFailed.add(1);
    } else if (status === 'PENDING') {
        ordersPending.add(1);
    }

    check(response, {
        'poll is not 500': (res) => res.status !== 500,
        'poll status is 200': (res) => res.status === 200,
        'order reached COMPLETED or FAILED': () => status === 'COMPLETED' || status === 'FAILED',
    });
}
