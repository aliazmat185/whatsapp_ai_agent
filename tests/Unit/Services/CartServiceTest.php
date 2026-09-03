<?php

namespace Tests\Unit\Services;

use App\Models\Conversation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Vendor;
use App\Services\Commerce\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CartServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_or_create_open_cart_reuses_existing_cart(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $service = new CartService();
        $first = $service->getOrCreateOpenCart($conversation);
        $second = $service->getOrCreateOpenCart($conversation);

        $this->assertSame($first->id, $second->id);
    }

    public function test_cannot_create_cart_without_resolved_store(): void
    {
        $conversation = Conversation::factory()->create(['store_id' => null]);

        $this->expectException(InvalidArgumentException::class);

        (new CartService())->getOrCreateOpenCart($conversation);
    }

    public function test_add_item_snapshots_price_at_add_time(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'base_price' => 1000]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $service = new CartService();
        $cart = $service->getOrCreateOpenCart($conversation);
        $item = $service->addItem($cart, $product->id, 2);

        $this->assertSame(1000.0, (float) $item->unit_price);
        $this->assertSame(2, $item->quantity);

        // Price changes after add-time should NOT retroactively change the cart item.
        $product->update(['base_price' => 5000]);
        $this->assertSame(1000.0, (float) $item->fresh()->unit_price);
    }

    public function test_add_item_includes_variant_price_delta(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'base_price' => 1000]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'Large', 'price_delta' => 200]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $service = new CartService();
        $cart = $service->getOrCreateOpenCart($conversation);
        $item = $service->addItem($cart, $product->id, 1, $variant->id);

        $this->assertSame(1200.0, (float) $item->unit_price);
    }

    public function test_adding_same_product_twice_increments_quantity_not_duplicate_row(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $service = new CartService();
        $cart = $service->getOrCreateOpenCart($conversation);
        $service->addItem($cart, $product->id, 1);
        $service->addItem($cart, $product->id, 2);

        $this->assertSame(1, $cart->items()->count());
        $this->assertSame(3, $cart->items()->first()->quantity);
    }

    public function test_cannot_add_product_from_another_store(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $storeA = Store::factory()->create(['vendor_id' => $vendor->id]);
        $storeB = Store::factory()->create(['vendor_id' => $vendor->id]);
        $productB = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $storeB->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $storeA->id]);

        $service = new CartService();
        $cart = $service->getOrCreateOpenCart($conversation);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $service->addItem($cart, $productB->id, 1);
    }

    public function test_cart_subtotal_sums_all_items(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $productA = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'base_price' => 500]);
        $productB = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'base_price' => 300]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $service = new CartService();
        $cart = $service->getOrCreateOpenCart($conversation);
        $service->addItem($cart, $productA->id, 2); // 1000
        $service->addItem($cart, $productB->id, 1); // 300

        $this->assertSame(1300.0, $cart->fresh()->load('items')->subtotal());
    }

    public function test_update_quantity_to_zero_removes_item(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $service = new CartService();
        $cart = $service->getOrCreateOpenCart($conversation);
        $item = $service->addItem($cart, $product->id, 1);

        $service->updateQuantity($item, 0);

        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
    }
}
