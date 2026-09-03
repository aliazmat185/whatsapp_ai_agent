<?php

namespace Tests\Unit\Services;

use App\Models\Conversation;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Store;
use App\Models\StoreLocation;
use App\Models\Vendor;
use App\Models\VendorPackage;
use App\Models\WhatsappAccount;
use App\Services\Ai\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function registry(): ToolRegistry
    {
        return app(ToolRegistry::class);
    }

    public function test_schemas_excludes_checkout_tools_for_faq_only_goal(): void
    {
        $names = collect($this->registry()->schemas('faq_only'))->pluck('name');

        $this->assertNotContains('add_to_cart', $names);
        $this->assertNotContains('get_cart', $names);
        $this->assertNotContains('start_checkout', $names);
        $this->assertContains('search_products', $names);
        $this->assertContains('search_knowledge_base', $names);
    }

    public function test_schemas_includes_checkout_tools_by_default(): void
    {
        $names = collect($this->registry()->schemas())->pluck('name');

        $this->assertContains('add_to_cart', $names);
        $this->assertContains('start_checkout', $names);
    }

    public function test_search_products_returns_error_without_resolved_store(): void
    {
        $conversation = Conversation::factory()->create(['store_id' => null]);

        $result = $this->registry()->execute('search_products', ['query' => 'charger'], $conversation);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_search_products_only_returns_products_from_resolved_store(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $storeA = Store::factory()->create(['vendor_id' => $vendor->id]);
        $storeB = Store::factory()->create(['vendor_id' => $vendor->id]);

        $productA = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $storeA->id, 'name' => 'Charger A']);
        Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $storeB->id, 'name' => 'Charger B']);

        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $storeA->id]);

        $result = $this->registry()->execute('search_products', [], $conversation);

        $names = array_column($result['products'], 'name');
        $this->assertContains('Charger A', $names);
        $this->assertNotContains('Charger B', $names);
    }

    public function test_search_products_reports_real_stock_not_ai_guessed(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        Inventory::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 0]);

        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $result = $this->registry()->execute('search_products', [], $conversation);

        $this->assertFalse($result['products'][0]['in_stock']);
    }

    public function test_get_store_candidates_requires_location(): void
    {
        $conversation = Conversation::factory()->create(['customer_lat' => null, 'customer_lng' => null]);

        $result = $this->registry()->execute('get_store_candidates', [], $conversation);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_store_candidates_ranks_by_distance(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $near = Store::factory()->create(['vendor_id' => $vendor->id, 'name' => 'Near']);
        StoreLocation::factory()->create(['store_id' => $near->id, 'latitude' => 24.861, 'longitude' => 67.011]);
        $far = Store::factory()->create(['vendor_id' => $vendor->id, 'name' => 'Far']);
        StoreLocation::factory()->create(['store_id' => $far->id, 'latitude' => 25.40, 'longitude' => 68.35]);

        $conversation = Conversation::factory()->create([
            'vendor_id' => $vendor->id,
            'customer_lat' => 24.86,
            'customer_lng' => 67.01,
        ]);

        $result = $this->registry()->execute('get_store_candidates', [], $conversation);

        $this->assertSame('Near', $result['stores'][0]['name']);
    }

    public function test_escalate_to_human_sets_needs_attention(): void
    {
        $conversation = Conversation::factory()->create();

        $result = $this->registry()->execute('escalate_to_human', ['reason' => 'cannot resolve'], $conversation);

        $this->assertSame('escalated', $result['status']);
        $this->assertSame('needs_attention', $conversation->fresh()->status);
    }

    public function test_request_location_advances_state(): void
    {
        $conversation = Conversation::factory()->create();

        $this->registry()->execute('request_location', [], $conversation);

        $this->assertSame('awaiting_location', $conversation->state->fresh()->current_step);
    }

    public function test_unknown_tool_returns_error(): void
    {
        $conversation = Conversation::factory()->create();

        $result = $this->registry()->execute('delete_everything', [], $conversation);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_add_to_cart_creates_cart_and_item(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'base_price' => 750]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $result = $this->registry()->execute('add_to_cart', ['product_id' => $product->id, 'quantity' => 2], $conversation);

        $this->assertSame('added', $result['status']);
        $this->assertSame(1500.0, $result['cart_subtotal']);
        $this->assertSame('cart_review', $conversation->state->fresh()->current_step);
    }

    public function test_add_to_cart_without_store_returns_error(): void
    {
        $conversation = Conversation::factory()->create(['store_id' => null]);

        $result = $this->registry()->execute('add_to_cart', ['product_id' => 1], $conversation);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_cart_returns_empty_when_no_cart(): void
    {
        $conversation = Conversation::factory()->create();

        $result = $this->registry()->execute('get_cart', [], $conversation);

        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['subtotal']);
    }

    public function test_get_cart_reflects_added_items(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'base_price' => 400]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $this->registry()->execute('add_to_cart', ['product_id' => $product->id, 'quantity' => 3], $conversation);
        $result = $this->registry()->execute('get_cart', [], $conversation);

        $this->assertCount(1, $result['items']);
        $this->assertSame(1200.0, $result['subtotal']);
    }

    public function test_start_checkout_reports_missing_fields(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $this->registry()->execute('add_to_cart', ['product_id' => $product->id], $conversation);
        $result = $this->registry()->execute('start_checkout', [], $conversation);

        $this->assertSame('missing_fields', $result['status']);
        $this->assertContains('customer_name', $result['missing']);
        $this->assertContains('city', $result['missing']);
        $this->assertContains('customer_address', $result['missing']);
        $this->assertContains('payment_method', $result['missing']);
    }

    public function test_start_checkout_asks_for_payment_method_when_omitted(): void
    {
        $package = VendorPackage::factory()->create(['enabled_payment_methods' => ['cod']]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'base_price' => 900]);
        Inventory::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 5]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $this->registry()->execute('add_to_cart', ['product_id' => $product->id], $conversation);
        $result = $this->registry()->execute('start_checkout', [
            'customer_name' => 'Ali',
            'city' => 'Lahore',
            'customer_address' => 'House 12, Gulberg',
        ], $conversation);

        $this->assertSame('missing_fields', $result['status']);
        $this->assertContains('payment_method', $result['missing']);
        $this->assertStringContainsString('Cash on Delivery', $result['message']);
        $this->assertDatabaseMissing('orders', ['payment_method' => 'cod']);
    }

    public function test_start_checkout_places_order_when_complete(): void
    {
        $package = VendorPackage::factory()->create(['enabled_payment_methods' => ['cod']]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'base_price' => 900]);
        Inventory::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 5]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $this->registry()->execute('add_to_cart', ['product_id' => $product->id], $conversation);

        $quote = $this->registry()->execute('start_checkout', [
            'customer_name' => 'Ali',
            'city' => 'Lahore',
            'customer_address' => 'House 12, Gulberg',
            'payment_method' => 'cod',
        ], $conversation);

        $this->assertSame('confirm_required', $quote['status']);
        $this->assertSame(900.0, $quote['subtotal']);
        $this->assertSame(900.0, $quote['total']);
        $this->assertDatabaseMissing('orders', ['payment_method' => 'cod']);

        $result = $this->registry()->execute('start_checkout', [
            'customer_name' => 'Ali',
            'city' => 'Lahore',
            'customer_address' => 'House 12, Gulberg',
            'payment_method' => 'cod',
            'confirm' => true,
        ], $conversation);

        $this->assertSame('order_placed', $result['status']);
        $this->assertSame(900.0, $result['total']);
        $this->assertDatabaseHas('orders', ['order_number' => $result['order_number']]);
        $this->assertSame('order_placed', $conversation->state->fresh()->current_step);
    }

    public function test_start_checkout_rejects_disallowed_payment_method(): void
    {
        $package = VendorPackage::factory()->create(['enabled_payment_methods' => ['cod']]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $this->registry()->execute('add_to_cart', ['product_id' => $product->id], $conversation);
        $result = $this->registry()->execute('start_checkout', [
            'customer_name' => 'Ali',
            'city' => 'Lahore',
            'customer_address' => 'House 12, Gulberg',
            'payment_method' => 'card',
        ], $conversation);

        $this->assertSame('invalid', $result['status']);
    }

    public function test_start_checkout_flags_online_payment_as_unavailable(): void
    {
        $package = VendorPackage::factory()->create(['enabled_payment_methods' => ['cod', 'card']]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'base_price' => 900]);
        Inventory::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 5]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $this->registry()->execute('add_to_cart', ['product_id' => $product->id], $conversation);
        $result = $this->registry()->execute('start_checkout', [
            'customer_name' => 'Ali',
            'city' => 'Lahore',
            'customer_address' => 'House 12, Gulberg',
            'payment_method' => 'card',
        ], $conversation);

        $this->assertSame('online_payment_unavailable', $result['status']);
        $this->assertStringContainsString('Cash on Delivery', $result['message']);
        $this->assertDatabaseMissing('orders', ['customer_name' => 'Ali']);
        $this->assertArrayNotHasKey('payment_method', $conversation->state->fresh()->context['pending_checkout'] ?? []);
    }

    public function test_start_checkout_with_empty_cart_errors(): void
    {
        $conversation = Conversation::factory()->create();

        $result = $this->registry()->execute('start_checkout', [
            'customer_name' => 'Ali',
            'customer_address' => 'Test',
            'payment_method' => 'cod',
        ], $conversation);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_search_products_remembers_results_in_conversation_context(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'name' => 'Chicken Biryani', 'base_price' => 400]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $this->registry()->execute('search_products', ['query' => 'biryani'], $conversation);

        $context = $conversation->state->fresh()->context;
        $this->assertNotEmpty($context['recent_products']);
        $this->assertSame($product->id, $context['recent_products'][0]['id']);
        $this->assertSame('Chicken Biryani', $context['recent_products'][0]['name']);
    }

    public function test_add_to_cart_clears_remembered_products(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $this->registry()->execute('search_products', [], $conversation);
        $this->assertNotEmpty($conversation->state->fresh()->context['recent_products'] ?? []);

        $this->registry()->execute('add_to_cart', ['product_id' => $product->id], $conversation);

        $this->assertEmpty($conversation->state->fresh()->context['recent_products'] ?? []);
    }

    public function test_update_cart_item_changes_quantity(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'base_price' => 400]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $this->registry()->execute('add_to_cart', ['product_id' => $product->id, 'quantity' => 2], $conversation);
        $result = $this->registry()->execute('update_cart_item', ['product_id' => $product->id, 'quantity' => 5], $conversation);

        $this->assertSame('updated', $result['status']);
        $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => 5]);
    }

    public function test_update_cart_item_to_zero_removes_it(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $this->registry()->execute('add_to_cart', ['product_id' => $product->id], $conversation);
        $this->registry()->execute('update_cart_item', ['product_id' => $product->id, 'quantity' => 0], $conversation);

        $this->assertDatabaseMissing('cart_items', ['product_id' => $product->id]);
    }

    public function test_update_cart_item_errors_when_not_in_cart(): void
    {
        $conversation = Conversation::factory()->create();

        $result = $this->registry()->execute('update_cart_item', ['product_id' => 999, 'quantity' => 1], $conversation);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_remove_from_cart_removes_item(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $this->registry()->execute('add_to_cart', ['product_id' => $product->id], $conversation);
        $result = $this->registry()->execute('remove_from_cart', ['product_id' => $product->id], $conversation);

        $this->assertSame('removed', $result['status']);
        $this->assertDatabaseMissing('cart_items', ['product_id' => $product->id]);
    }

    public function test_remove_from_cart_errors_when_not_in_cart(): void
    {
        $conversation = Conversation::factory()->create();

        $result = $this->registry()->execute('remove_from_cart', ['product_id' => 999], $conversation);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_empty_cart_clears_all_items(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $productA = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $productB = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $this->registry()->execute('add_to_cart', ['product_id' => $productA->id], $conversation);
        $this->registry()->execute('add_to_cart', ['product_id' => $productB->id], $conversation);

        $result = $this->registry()->execute('empty_cart', [], $conversation);

        $this->assertSame('emptied', $result['status']);
        $this->assertSame(0, $conversation->activeCart()->fresh()->items()->count());
    }

    public function test_empty_cart_is_a_noop_without_a_cart(): void
    {
        $conversation = Conversation::factory()->create();

        $result = $this->registry()->execute('empty_cart', [], $conversation);

        $this->assertSame('emptied', $result['status']);
    }

    public function test_show_menu_sends_category_list_and_returns_categories(): void
    {
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

        $vendor = Vendor::factory()->approved()->create();
        WhatsappAccount::factory()->create(['vendor_id' => $vendor->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $category = ProductCategory::factory()->create(['vendor_id' => $vendor->id, 'name' => 'Biryani & Rice']);
        Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'category_id' => $category->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $result = $this->registry()->execute('show_menu', [], $conversation);

        $this->assertSame('categories_sent', $result['status']);
        $this->assertSame('Biryani & Rice', $result['categories'][0]['name']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && $request['interactive']['type'] === 'list'
            && $request['interactive']['action']['sections'][0]['rows'][0]['id'] === "category:{$category->id}");
    }

    public function test_show_menu_with_category_id_sends_that_categorys_products(): void
    {
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

        $vendor = Vendor::factory()->approved()->create();
        WhatsappAccount::factory()->create(['vendor_id' => $vendor->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $category = ProductCategory::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'category_id' => $category->id, 'name' => 'Chicken Biryani']);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $result = $this->registry()->execute('show_menu', ['category_id' => $category->id], $conversation);

        $this->assertSame('products_sent', $result['status']);
        $this->assertSame('Chicken Biryani', $result['products'][0]['name']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com')
            && $request['interactive']['action']['sections'][0]['rows'][0]['id'] === "product:{$product->id}");
    }

    public function test_start_checkout_remembers_partial_info_across_calls(): void
    {
        $package = VendorPackage::factory()->create(['enabled_payment_methods' => ['cod']]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'base_price' => 400]);
        Inventory::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 5]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $this->registry()->execute('add_to_cart', ['product_id' => $product->id], $conversation);

        // Turn 1: only the name is given — city, address, payment method still missing.
        $result = $this->registry()->execute('start_checkout', ['customer_name' => 'Ali Azmat'], $conversation);
        $this->assertSame('missing_fields', $result['status']);
        $this->assertSame(['city', 'customer_address', 'payment_method'], $result['missing']);

        // Turn 2: a later call supplies city + address — the name from
        // turn 1 must still be remembered, not re-requested.
        $result = $this->registry()->execute('start_checkout', ['city' => 'Lahore', 'customer_address' => 'House 5, Gulberg'], $conversation);
        $this->assertSame('missing_fields', $result['status']);
        $this->assertSame(['payment_method'], $result['missing']);

        // Turn 3: payment method given — everything is now known, but the
        // order still isn't placed without an explicit confirmation.
        $quote = $this->registry()->execute('start_checkout', ['payment_method' => 'cod'], $conversation);
        $this->assertSame('confirm_required', $quote['status']);

        // Turn 4: customer confirms — nothing needs to be repeated at all.
        $result = $this->registry()->execute('start_checkout', ['confirm' => true], $conversation);

        $this->assertSame('order_placed', $result['status']);
        $this->assertDatabaseHas('orders', ['order_number' => $result['order_number'], 'customer_name' => 'Ali Azmat', 'customer_address' => 'House 5, Gulberg, Lahore']);
    }

    public function test_show_menu_errors_without_resolved_store(): void
    {
        $conversation = Conversation::factory()->create(['store_id' => null]);

        $result = $this->registry()->execute('show_menu', [], $conversation);

        $this->assertArrayHasKey('error', $result);
    }
}
