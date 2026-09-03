<?php

namespace Tests\Unit\Services;

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

class CheckoutServiceTest extends TestCase
{
    use RefreshDatabase;

    private function checkoutService(): CheckoutService
    {
        return app(CheckoutService::class);
    }

    public function test_empty_cart_fails_validation(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $cart = (new CartService())->getOrCreateOpenCart($conversation);

        $result = $this->checkoutService()->validate($cart, 'cod');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('empty', $result['errors'][0]);
    }

    public function test_out_of_stock_item_fails_validation(): void
    {
        $package = VendorPackage::factory()->create(['enabled_payment_methods' => ['cod']]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        Inventory::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 1]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $cartService = new CartService();
        $cart = $cartService->getOrCreateOpenCart($conversation);
        $cartService->addItem($cart, $product->id, 5); // more than the 1 in stock

        $result = $this->checkoutService()->validate($cart->fresh(), 'cod');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('left in stock', $result['errors'][0]);
    }

    public function test_disallowed_payment_method_fails_validation(): void
    {
        $package = VendorPackage::factory()->create(['enabled_payment_methods' => ['cod']]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $cartService = new CartService();
        $cart = $cartService->getOrCreateOpenCart($conversation);
        $cartService->addItem($cart, $product->id, 1);

        $result = $this->checkoutService()->validate($cart->fresh(), 'card');

        $this->assertFalse($result['valid']);
    }

    public function test_valid_cart_creates_order_with_correct_total_and_decrements_stock(): void
    {
        $package = VendorPackage::factory()->create(['enabled_payment_methods' => ['cod']]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'base_price' => 500]);
        Inventory::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 10]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'customer_phone' => '923001234567']);

        $cartService = new CartService();
        $cart = $cartService->getOrCreateOpenCart($conversation);
        $cartService->addItem($cart, $product->id, 3);

        $order = $this->checkoutService()->createOrder($cart->fresh(), 'cod', 'Ali', 'House 1, Street 2');

        $this->assertSame(1500.0, (float) $order->total);
        $this->assertSame('923001234567', $order->customer_phone);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame('converted', $cart->fresh()->status);

        $inventory = Inventory::where('product_id', $product->id)->first();
        $this->assertSame(7, $inventory->quantity);
    }

    public function test_inactive_product_fails_validation(): void
    {
        $package = VendorPackage::factory()->create(['enabled_payment_methods' => ['cod']]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $cartService = new CartService();
        $cart = $cartService->getOrCreateOpenCart($conversation);
        $cartService->addItem($cart, $product->id, 1);

        $product->update(['is_active' => false]);

        $result = $this->checkoutService()->validate($cart->fresh(), 'cod');

        $this->assertFalse($result['valid']);
    }

    public function test_suspended_vendor_fails_validation(): void
    {
        $package = VendorPackage::factory()->create(['enabled_payment_methods' => ['cod']]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $cartService = new CartService();
        $cart = $cartService->getOrCreateOpenCart($conversation);
        $cartService->addItem($cart, $product->id, 1);

        $vendor->update(['status' => 'suspended']);

        $result = $this->checkoutService()->validate($cart->fresh(), 'cod');

        $this->assertFalse($result['valid']);
    }
}
