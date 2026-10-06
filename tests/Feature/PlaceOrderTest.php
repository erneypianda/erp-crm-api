<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\OrderPlaced;
use App\Exceptions\InsufficientStockException;
use App\Listeners\ProcessStockReservation;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlaceOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_accepts_the_order_without_touching_stock(): void
    {
        Event::fake([OrderPlaced::class]);

        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['stock' => 5]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 10,
                ],
            ],
        ]);

        $response->assertAccepted()
            ->assertJsonPath('message', 'Order received and is being processed.')
            ->assertJsonPath('status', 'PENDING');

        $uuid = $response->json('order_uuid');
        $this->assertTrue(Str::isUuid($uuid));
        $response->assertJsonPath('status_url', '/api/sales/uuid/'.$uuid);

        Sanctum::actingAs($user);

        $this->getJson('/api/sales/uuid/'.$uuid)
            ->assertOk()
            ->assertJsonPath('order_uuid', $uuid)
            ->assertJsonPath('status', 'PENDING')
            ->assertJsonPath('status_url', '/api/sales/uuid/'.$uuid);

        $this->assertDatabaseHas('sales', [
            'uuid' => $uuid,
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'status' => 'PENDING',
            'subtotal' => 20,
            'tax' => 4.2,
            'total' => 24.2,
        ]);

        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertDatabaseCount('sale_items', 0);

        Event::assertDispatched(OrderPlaced::class, function (OrderPlaced $event) use ($uuid, $product): bool {
            return $event->sale->uuid === $uuid
                && (int) $event->items[0]['product_id'] === $product->id
                && (int) $event->items[0]['quantity'] === 2;
        });
    }

    public function test_listener_reserves_stock_and_completes_the_sale(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['stock' => 5]);
        $sale = $this->pendingSale($customer, $user);

        $listener = new ProcessStockReservation;
        $listener->handle(new OrderPlaced($sale, [
            [
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 10,
            ],
        ]));

        $this->assertSame('COMPLETED', $sale->fresh()->status);
        $this->assertSame(3, (int) $product->fresh()->stock);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 10,
            'total_price' => 20,
        ]);
    }

    public function test_listener_failure_restores_stock_and_marks_the_sale_failed(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $available = Product::factory()->create(['stock' => 4]);
        $missing = Product::factory()->create(['stock' => 1]);
        $sale = $this->pendingSale($customer, $user);

        $event = new OrderPlaced($sale, [
            [
                'product_id' => $available->id,
                'quantity' => 2,
                'unit_price' => 10,
            ],
            [
                'product_id' => $missing->id,
                'quantity' => 5,
                'unit_price' => 8,
            ],
        ]);

        $listener = new ProcessStockReservation;

        try {
            $listener->handle($event);
            $this->fail('Se esperaba un fallo de stock.');
        } catch (InsufficientStockException $exception) {
            $listener->failed($event, $exception);
        }

        $this->assertSame('FAILED', $sale->fresh()->status);
        $this->assertSame(4, (int) $available->fresh()->stock);
        $this->assertSame(1, (int) $missing->fresh()->stock);
        $this->assertDatabaseCount('sale_items', 0);
    }

    public function test_cancel_restores_stock_only_for_completed_orders(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['stock' => 5]);
        $sale = $this->pendingSale($customer, $admin);

        $listener = new ProcessStockReservation;
        $listener->handle(new OrderPlaced($sale, [
            [
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 10,
            ],
        ]));

        Sanctum::actingAs($admin);

        $this->deleteJson('/api/sales/'.$sale->uuid)
            ->assertOk()
            ->assertJsonPath('order_uuid', $sale->uuid)
            ->assertJsonPath('status', 'CANCELLED');

        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertSame('CANCELLED', $sale->fresh()->status);

        $this->deleteJson('/api/sales/'.$sale->uuid)
            ->assertStatus(409);
    }

    private function pendingSale(Customer $customer, User $user): Sale
    {
        return Sale::query()->create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'user_id' => $user->id,
            'subtotal' => 20,
            'tax' => 4.2,
            'total' => 24.2,
            'status' => 'PENDING',
        ]);
    }
}
