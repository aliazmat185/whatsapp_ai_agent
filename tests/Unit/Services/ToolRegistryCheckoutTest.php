<?php

namespace Tests\Unit\Services;

use App\Models\Conversation;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreLocation;
use App\Models\Vendor;
use App\Models\VendorPackage;
use App\Services\Ai\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the checkout-flow bugfix: a real conversation let the AI place a
 * confirmed COD order with no explicit payment choice and an out-of-area,
 * incomplete address ("Omer Chaudhary Paris" for a Lahore-only store). See
 * ToolRegistry::startCheckout().
 */
class ToolRegistryCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function registry(): ToolRegistry
    {
        return app(ToolRegistry::class);
    }

    /**
     * @return array{vendor: Vendor, store: Store, product: Product, conversation: Conversation}
     */
    private function setUpLahoreStore(array $enabledPaymentMethods = ['cod']): array
    {
        $package = VendorPackage::factory()->create(['enabled_payment_methods' => $enabledPaymentMethods]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        StoreLocation::factory()->create(['store_id' => $store->id, 'city' => 'Lahore']);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'base_price' => 900]);
        Inventory::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 5]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        return ['vendor' => $vendor, 'store' => $store, 'product' => $product, 'conversation' => $conversation];
    }

    public function test_valid_lahore_delivery_with_confirmed_cod_places_order(): void
    {
        $ctx = $this->setUpLahoreStore();
        $this->registry()->execute('add_to_cart', ['product_id' => $ctx['product']->id], $ctx['conversation']);

        $quote = $this->registry()->execute('start_checkout', [
            'customer_name' => 'Omer Chaudhary',
            'city' => 'Lahore',
            'customer_address' => 'House 12, Street 5, Gulberg',
            'payment_method' => 'cod',
        ], $ctx['conversation']);

        $this->assertSame('confirm_required', $quote['status']);

        $result = $this->registry()->execute('start_checkout', [
            'customer_name' => 'Omer Chaudhary',
            'city' => 'Lahore',
            'customer_address' => 'House 12, Street 5, Gulberg',
            'payment_method' => 'cod',
            'confirm' => true,
        ], $ctx['conversation']);

        $this->assertSame('order_placed', $result['status']);
        $this->assertDatabaseHas('orders', ['order_number' => $result['order_number'], 'payment_method' => 'cod']);
    }

    public function test_out_of_area_delivery_is_rejected_and_no_order_created(): void
    {
        $ctx = $this->setUpLahoreStore();
        $this->registry()->execute('add_to_cart', ['product_id' => $ctx['product']->id], $ctx['conversation']);

        $result = $this->registry()->execute('start_checkout', [
            'customer_name' => 'Omer Chaudhary',
            'city' => 'Paris',
            'customer_address' => 'Somewhere in Paris',
            'payment_method' => 'cod',
        ], $ctx['conversation']);

        $this->assertSame('delivery_unavailable', $result['status']);
        $this->assertStringContainsString('Lahore', $result['message']);
        $this->assertDatabaseMissing('orders', ['customer_name' => 'Omer Chaudhary']);
    }

    public function test_reported_bug_scenario_is_rejected(): void
    {
        // Replays the exact reported conversation's final state: a bare
        // name+city string with no separate address, city outside the
        // store's delivery area, no explicit payment method ever given.
        $ctx = $this->setUpLahoreStore();
        $this->registry()->execute('add_to_cart', ['product_id' => $ctx['product']->id], $ctx['conversation']);

        $result = $this->registry()->execute('start_checkout', [
            'customer_name' => 'Omer Chaudhary',
            'city' => 'Paris',
            'customer_address' => 'Paris',
        ], $ctx['conversation']);

        $this->assertNotSame('order_placed', $result['status']);
        $this->assertDatabaseMissing('orders', ['customer_name' => 'Omer Chaudhary']);
    }

    public function test_incomplete_address_is_treated_as_missing(): void
    {
        $ctx = $this->setUpLahoreStore();
        $this->registry()->execute('add_to_cart', ['product_id' => $ctx['product']->id], $ctx['conversation']);

        $result = $this->registry()->execute('start_checkout', [
            'customer_name' => 'Omer Chaudhary',
            'city' => 'Lahore',
            'customer_address' => 'Lahore',
            'payment_method' => 'cod',
        ], $ctx['conversation']);

        $this->assertSame('missing_fields', $result['status']);
        $this->assertContains('customer_address', $result['missing']);
        $this->assertDatabaseMissing('orders', ['customer_name' => 'Omer Chaudhary']);
    }

    public function test_missing_payment_method_asks_with_all_four_options(): void
    {
        $ctx = $this->setUpLahoreStore();
        $this->registry()->execute('add_to_cart', ['product_id' => $ctx['product']->id], $ctx['conversation']);

        $result = $this->registry()->execute('start_checkout', [
            'customer_name' => 'Omer Chaudhary',
            'city' => 'Lahore',
            'customer_address' => 'House 12, Street 5, Gulberg',
        ], $ctx['conversation']);

        $this->assertSame('missing_fields', $result['status']);
        $this->assertContains('payment_method', $result['missing']);
        foreach (['Cash on Delivery', 'Card', 'JazzCash', 'EasyPaisa'] as $option) {
            $this->assertStringContainsString($option, $result['message']);
        }
    }

    public function test_online_payment_request_never_falls_back_to_cod(): void
    {
        $ctx = $this->setUpLahoreStore(['cod', 'jazzcash']);
        $this->registry()->execute('add_to_cart', ['product_id' => $ctx['product']->id], $ctx['conversation']);

        $result = $this->registry()->execute('start_checkout', [
            'customer_name' => 'Omer Chaudhary',
            'city' => 'Lahore',
            'customer_address' => 'House 12, Street 5, Gulberg',
            'payment_method' => 'jazzcash',
        ], $ctx['conversation']);

        $this->assertSame('online_payment_unavailable', $result['status']);
        $this->assertDatabaseMissing('orders', ['customer_name' => 'Omer Chaudhary']);

        $context = $ctx['conversation']->state->fresh()->context['pending_checkout'] ?? [];
        $this->assertArrayNotHasKey('payment_method', $context);

        // Confirming right after, with no payment method re-specified, must
        // NOT silently succeed as cod.
        $followUp = $this->registry()->execute('start_checkout', ['confirm' => true], $ctx['conversation']);
        $this->assertNotSame('order_placed', $followUp['status']);
        $this->assertDatabaseMissing('orders', ['customer_name' => 'Omer Chaudhary']);
    }

    public function test_price_change_between_quote_and_confirm_is_reflected_in_final_total(): void
    {
        $ctx = $this->setUpLahoreStore();
        $this->registry()->execute('add_to_cart', ['product_id' => $ctx['product']->id], $ctx['conversation']);

        $quote = $this->registry()->execute('start_checkout', [
            'customer_name' => 'Omer Chaudhary',
            'city' => 'Lahore',
            'customer_address' => 'House 12, Street 5, Gulberg',
            'payment_method' => 'cod',
        ], $ctx['conversation']);

        $this->assertSame(900.0, $quote['total']);

        // Price changes after the quote was shown but before confirmation.
        $ctx['product']->update(['base_price' => 1200]);

        $result = $this->registry()->execute('start_checkout', ['confirm' => true], $ctx['conversation']);

        $this->assertSame('order_placed', $result['status']);
        $this->assertSame(1200.0, $result['total']);
        $this->assertDatabaseHas('orders', ['order_number' => $result['order_number'], 'total' => 1200.0]);
    }

    public function test_confirm_is_required_before_order_is_created(): void
    {
        $ctx = $this->setUpLahoreStore();
        $this->registry()->execute('add_to_cart', ['product_id' => $ctx['product']->id], $ctx['conversation']);

        $result = $this->registry()->execute('start_checkout', [
            'customer_name' => 'Omer Chaudhary',
            'city' => 'Lahore',
            'customer_address' => 'House 12, Street 5, Gulberg',
            'payment_method' => 'cod',
        ], $ctx['conversation']);

        $this->assertSame('confirm_required', $result['status']);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('subtotal', $result);
        $this->assertArrayHasKey('delivery_fee', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertDatabaseMissing('orders', ['customer_name' => 'Omer Chaudhary']);
    }

    public function test_fractional_quantity_is_rejected(): void
    {
        $ctx = $this->setUpLahoreStore();

        $result = $this->registry()->execute('add_to_cart', [
            'product_id' => $ctx['product']->id,
            'quantity' => 1.5,
        ], $ctx['conversation']);

        $this->assertArrayHasKey('error', $result);
        $this->assertDatabaseMissing('cart_items', ['product_id' => $ctx['product']->id]);
    }

    public function test_store_without_configured_location_allows_any_city(): void
    {
        // No StoreLocation at all — delivery-zone checking has nothing to
        // enforce against, so existing stores that never configured one
        // must keep working exactly as before this fix.
        $package = VendorPackage::factory()->create(['enabled_payment_methods' => ['cod']]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'base_price' => 500]);
        Inventory::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 5]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $this->registry()->execute('add_to_cart', ['product_id' => $product->id], $conversation);

        $result = $this->registry()->execute('start_checkout', [
            'customer_name' => 'Omer Chaudhary',
            'city' => 'Anywhere',
            'customer_address' => 'House 12, Street 5',
            'payment_method' => 'cod',
            'confirm' => true,
        ], $conversation);

        $this->assertSame('order_placed', $result['status']);
    }
}
