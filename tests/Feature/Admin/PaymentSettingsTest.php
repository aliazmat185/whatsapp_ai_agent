<?php

namespace Tests\Feature\Admin;

use App\Models\Conversation;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPackage;
use App\Services\Commerce\CartService;
use App\Services\Commerce\CheckoutService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_super_admin_can_toggle_payment_method(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        Livewire::actingAs($admin)
            ->test('admin.payment-settings')
            ->call('toggle', 'jazzcash');

        $this->assertFalse((bool) \App\Models\SystemSetting::get('payment_methods.jazzcash.enabled'));
    }

    public function test_vendor_cannot_access_payment_settings(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $vendor->owner->assignRole('vendor_owner');
        $vendor->owner->update(['vendor_id' => $vendor->id]);

        Livewire::actingAs($vendor->owner)
            ->test('admin.payment-settings')
            ->assertForbidden();
    }

    public function test_globally_disabled_method_fails_checkout_even_if_package_allows_it(): void
    {
        \App\Models\SystemSetting::set('payment_methods.jazzcash.enabled', false);

        $package = VendorPackage::factory()->create(['enabled_payment_methods' => ['cod', 'jazzcash']]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $cartService = new CartService();
        $cart = $cartService->getOrCreateOpenCart($conversation);
        $cartService->addItem($cart, $product->id, 1);

        $result = app(CheckoutService::class)->validate($cart->fresh(), 'jazzcash');

        $this->assertFalse($result['valid']);
    }

    public function test_package_allowed_and_globally_enabled_method_passes(): void
    {
        \App\Models\SystemSetting::set('payment_methods.cod.enabled', true);

        $package = VendorPackage::factory()->create(['enabled_payment_methods' => ['cod']]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        $cartService = new CartService();
        $cart = $cartService->getOrCreateOpenCart($conversation);
        $cartService->addItem($cart, $product->id, 1);

        $result = app(CheckoutService::class)->validate($cart->fresh(), 'cod');

        $this->assertTrue($result['valid']);
    }
}
