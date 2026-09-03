<?php

namespace Tests\Unit\Services;

use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Commerce\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_numbers_increment_sequentially_per_day(): void
    {
        $service = new OrderService();

        $first = $service->generateOrderNumber();
        Order::factory()->create(['order_number' => $first]);

        $second = $service->generateOrderNumber();

        $this->assertStringEndsWith('0001', $first);
        $this->assertStringEndsWith('0002', $second);
    }

    public function test_valid_transition_succeeds(): void
    {
        $order = Order::factory()->create(['status' => 'pending']);

        $updated = (new OrderService())->transitionStatus($order, 'confirmed');

        $this->assertSame('confirmed', $updated->status);
    }

    public function test_invalid_transition_throws(): void
    {
        $order = Order::factory()->create(['status' => 'pending']);

        $this->expectException(InvalidArgumentException::class);

        (new OrderService())->transitionStatus($order, 'out_for_delivery');
    }

    public function test_cannot_transition_out_of_completed(): void
    {
        $order = Order::factory()->create(['status' => 'completed']);

        $this->expectException(InvalidArgumentException::class);

        (new OrderService())->transitionStatus($order, 'pending');
    }

    public function test_cannot_transition_out_of_cancelled(): void
    {
        $order = Order::factory()->create(['status' => 'cancelled']);

        $this->expectException(InvalidArgumentException::class);

        (new OrderService())->transitionStatus($order, 'confirmed');
    }

    public function test_cancel_sets_reason(): void
    {
        $order = Order::factory()->create(['status' => 'confirmed']);

        $cancelled = (new OrderService())->cancel($order, 'Customer requested cancellation');

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('Customer requested cancellation', $cancelled->cancelled_reason);
    }

    public function test_full_lifecycle_transitions_in_order(): void
    {
        $order = Order::factory()->create(['status' => 'pending']);
        $service = new OrderService();

        foreach (['confirmed', 'preparing', 'out_for_delivery', 'completed'] as $status) {
            $order = $service->transitionStatus($order, $status);
        }

        $this->assertSame('completed', $order->status);
    }

    public function test_update_item_quantity_recalculates_totals_and_inventory(): void
    {
        $order = Order::factory()->create(['status' => 'pending', 'subtotal' => 800, 'delivery_fee' => 0, 'total' => 800]);
        $product = Product::factory()->create(['vendor_id' => $order->vendor_id, 'store_id' => $order->store_id, 'base_price' => 400]);
        Inventory::create(['store_id' => $order->store_id, 'product_id' => $product->id, 'quantity' => 5, 'track_stock' => true]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name,
            'quantity' => 2, 'unit_price' => 400, 'line_total' => 800,
        ]);

        $updated = (new OrderService())->updateItemQuantity($order, $item, 5);

        $this->assertSame(2000.0, (float) $updated->subtotal);
        $this->assertSame(2000.0, (float) $updated->total);
        $this->assertSame(2, Inventory::first()->quantity); // 5 - (5-2) more taken
    }

    public function test_update_item_quantity_rejects_insufficient_stock(): void
    {
        $order = Order::factory()->create(['status' => 'pending']);
        $product = Product::factory()->create(['vendor_id' => $order->vendor_id, 'store_id' => $order->store_id, 'base_price' => 400]);
        Inventory::create(['store_id' => $order->store_id, 'product_id' => $product->id, 'quantity' => 3, 'track_stock' => true]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name,
            'quantity' => 2, 'unit_price' => 400, 'line_total' => 800,
        ]);

        $this->expectException(InvalidArgumentException::class);

        (new OrderService())->updateItemQuantity($order, $item, 10);
    }

    public function test_remove_item_restores_inventory_and_recalculates(): void
    {
        $order = Order::factory()->create(['status' => 'pending']);
        $productA = Product::factory()->create(['vendor_id' => $order->vendor_id, 'store_id' => $order->store_id, 'base_price' => 400]);
        $productB = Product::factory()->create(['vendor_id' => $order->vendor_id, 'store_id' => $order->store_id, 'base_price' => 600]);
        Inventory::create(['store_id' => $order->store_id, 'product_id' => $productA->id, 'quantity' => 3, 'track_stock' => true]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $productA->id, 'product_name_snapshot' => $productA->name,
            'quantity' => 2, 'unit_price' => 400, 'line_total' => 800,
        ]);
        $itemB = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $productB->id, 'product_name_snapshot' => $productB->name,
            'quantity' => 1, 'unit_price' => 600, 'line_total' => 600,
        ]);

        $updated = (new OrderService())->removeItem($order, $itemB);

        $this->assertSame(800.0, (float) $updated->subtotal);
        $this->assertDatabaseMissing('order_items', ['id' => $itemB->id]);
    }

    public function test_remove_item_rejects_when_only_one_item(): void
    {
        $order = Order::factory()->create(['status' => 'pending']);
        $product = Product::factory()->create(['vendor_id' => $order->vendor_id, 'store_id' => $order->store_id]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name,
            'quantity' => 1, 'unit_price' => 400, 'line_total' => 400,
        ]);

        $this->expectException(InvalidArgumentException::class);

        (new OrderService())->removeItem($order, $item);
    }

    public function test_add_item_creates_line_and_decrements_inventory(): void
    {
        $order = Order::factory()->create(['status' => 'confirmed', 'subtotal' => 400, 'delivery_fee' => 0, 'total' => 400]);
        $existing = Product::factory()->create(['vendor_id' => $order->vendor_id, 'store_id' => $order->store_id, 'base_price' => 400]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $existing->id, 'product_name_snapshot' => $existing->name,
            'quantity' => 1, 'unit_price' => 400, 'line_total' => 400,
        ]);
        $newProduct = Product::factory()->create(['vendor_id' => $order->vendor_id, 'store_id' => $order->store_id, 'base_price' => 300]);
        Inventory::create(['store_id' => $order->store_id, 'product_id' => $newProduct->id, 'quantity' => 10, 'track_stock' => true]);

        $updated = (new OrderService())->addItem($order, $newProduct->id, 2);

        $this->assertSame(1000.0, (float) $updated->subtotal);
        $this->assertSame(8, Inventory::where('product_id', $newProduct->id)->first()->quantity);
    }

    public function test_edit_rejected_once_order_is_preparing(): void
    {
        $order = Order::factory()->create(['status' => 'preparing']);
        $product = Product::factory()->create(['vendor_id' => $order->vendor_id, 'store_id' => $order->store_id]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_name_snapshot' => $product->name,
            'quantity' => 1, 'unit_price' => 400, 'line_total' => 400,
        ]);

        $this->expectException(InvalidArgumentException::class);

        (new OrderService())->updateItemQuantity($order, $item, 3);
    }
}
