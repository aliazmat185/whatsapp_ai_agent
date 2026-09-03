<?php

namespace Tests\Feature\EdgeCases;

use App\Models\Conversation;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Store;
use App\Models\Vendor;
use App\Models\VendorPackage;
use App\Services\Commerce\CartService;
use App\Services\Commerce\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PLAN.md §14 Edge Cases — commerce-flow scenarios.
 */
class CommerceEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private function vendorStoreProduct(int $stock = 10): array
    {
        $package = VendorPackage::factory()->create(['enabled_payment_methods' => ['cod']]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'base_price' => 500]);
        Inventory::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => $stock]);

        return [$vendor, $store, $product];
    }

    /**
     * "Product goes out of stock between cart-add and checkout" — stock
     * drops after add-to-cart but before checkout; checkout must catch it.
     */
    public function test_stock_depleted_after_add_to_cart_blocks_checkout(): void
    {
        [$vendor, $store, $product] = $this->vendorStoreProduct(stock: 5);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $cartService = new CartService();
        $cart = $cartService->getOrCreateOpenCart($conversation);
        $cartService->addItem($cart, $product->id, 5); // exactly at stock limit, valid for now

        // Someone else buys the last 3 units before this customer checks out.
        Inventory::where('product_id', $product->id)->decrement('quantity', 3);

        $result = app(CheckoutService::class)->validate($cart->fresh(), 'cod');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('left in stock', $result['errors'][0]);
    }

    /**
     * "Vendor deletes/deactivates a product mid-conversation" — cart item
     * flagged invalid at checkout, same as stock-out case.
     */
    public function test_product_deactivated_after_add_to_cart_blocks_checkout(): void
    {
        [$vendor, $store, $product] = $this->vendorStoreProduct();
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $cartService = new CartService();
        $cart = $cartService->getOrCreateOpenCart($conversation);
        $cartService->addItem($cart, $product->id, 1);

        $product->update(['is_active' => false]);

        $result = app(CheckoutService::class)->validate($cart->fresh(), 'cod');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('no longer available', $result['errors'][0]);
    }

    /**
     * "Vendor suspended while customer mid-checkout" — order creation
     * blocked, customer told to try later.
     */
    public function test_vendor_suspended_mid_checkout_blocks_order(): void
    {
        [$vendor, $store, $product] = $this->vendorStoreProduct();
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $cartService = new CartService();
        $cart = $cartService->getOrCreateOpenCart($conversation);
        $cartService->addItem($cart, $product->id, 1);

        $vendor->update(['status' => 'suspended']);

        $result = app(CheckoutService::class)->validate($cart->fresh(), 'cod');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('unavailable', $result['errors'][0]);
    }

    /**
     * "Vendor has 0 stores (misconfigured)" — cannot resolve any store for
     * this conversation, add_to_cart/checkout must not silently break.
     */
    public function test_vendor_with_no_stores_cannot_create_cart(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => null]);

        $this->expectException(\InvalidArgumentException::class);

        (new CartService())->getOrCreateOpenCart($conversation);
    }

    /**
     * "Same customer phone messages two different vendors" — customer
     * identity is (vendor_id, phone)-scoped, not global (PLAN.md §3.5 unique
     * constraint + §14).
     */
    public function test_same_phone_creates_independent_conversations_per_vendor(): void
    {
        $vendorA = Vendor::factory()->approved()->create();
        $vendorB = Vendor::factory()->approved()->create();

        $convA = Conversation::factory()->create(['vendor_id' => $vendorA->id, 'customer_phone' => '923001112222']);
        $convB = Conversation::factory()->create(['vendor_id' => $vendorB->id, 'customer_phone' => '923001112222']);

        $this->assertNotSame($convA->id, $convB->id);
        $this->assertSame(2, Conversation::where('customer_phone', '923001112222')->count());
    }

    /**
     * "Package downgraded below current usage" — existing stores/products
     * NOT deleted; new creation blocked until under limit.
     */
    public function test_package_downgrade_does_not_delete_existing_stores(): void
    {
        $package = VendorPackage::factory()->create(['max_stores' => 5]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id]);
        Store::factory()->count(3)->create(['vendor_id' => $vendor->id]);

        // Admin downgrades the package to allow only 1 store.
        $package->update(['max_stores' => 1]);

        $this->assertSame(3, $vendor->stores()->count());
        $this->assertFalse(app(\App\Services\Vendor\PackageLimitService::class)->canAddStore($vendor->fresh()));
    }
}
