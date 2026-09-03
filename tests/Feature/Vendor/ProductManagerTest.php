<?php

namespace Tests\Feature\Vendor;

use App\Models\Product;
use App\Models\Store;
use App\Models\Vendor;
use App\Models\VendorPackage;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function approvedVendorOwnerWithStore(int $maxProducts = 5): array
    {
        $package = VendorPackage::factory()->create(['max_products' => $maxProducts]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id]);
        $vendor->owner->assignRole('vendor_owner');
        $vendor->owner->update(['vendor_id' => $vendor->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);

        return [$vendor, $store];
    }

    public function test_vendor_can_create_a_product_with_initial_stock(): void
    {
        [$vendor, $store] = $this->approvedVendorOwnerWithStore();

        Livewire::actingAs($vendor->owner)
            ->test('vendor.product-manager')
            ->call('create')
            ->set('name', 'Phone Charger')
            ->set('storeId', $store->id)
            ->set('basePrice', 1500)
            ->set('initialStock', 20)
            ->call('save');

        $product = Product::where('name', 'Phone Charger')->first();
        $this->assertNotNull($product);
        $this->assertSame($vendor->id, $product->vendor_id);
        $this->assertSame($store->id, $product->store_id);

        $this->assertDatabaseHas('inventories', [
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 20,
        ]);
    }

    public function test_product_cannot_be_assigned_to_another_vendors_store(): void
    {
        [$vendorA] = $this->approvedVendorOwnerWithStore();
        [, $storeB] = $this->approvedVendorOwnerWithStore();

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($vendorA->owner)
            ->test('vendor.product-manager')
            ->call('create')
            ->set('name', 'Sneaky Product')
            ->set('storeId', $storeB->id)
            ->set('basePrice', 500)
            ->call('save');
    }

    public function test_vendor_cannot_exceed_product_package_limit(): void
    {
        [$vendor, $store] = $this->approvedVendorOwnerWithStore(maxProducts: 1);
        Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        Livewire::actingAs($vendor->owner)
            ->test('vendor.product-manager')
            ->call('create')
            ->assertHasErrors(['name']);

        $this->assertSame(1, Product::where('vendor_id', $vendor->id)->count());
    }

    public function test_vendor_cannot_view_another_vendors_products(): void
    {
        [$vendorA] = $this->approvedVendorOwnerWithStore();
        [$vendorB, $storeB] = $this->approvedVendorOwnerWithStore();
        Product::factory()->create(['vendor_id' => $vendorB->id, 'store_id' => $storeB->id, 'name' => 'Hidden Product']);

        $component = Livewire::actingAs($vendorA->owner)->test('vendor.product-manager');

        $this->assertStringNotContainsString('Hidden Product', $component->html());
    }

    public function test_vendor_cannot_delete_another_vendors_product_image(): void
    {
        [$vendorA] = $this->approvedVendorOwnerWithStore();
        [$vendorB, $storeB] = $this->approvedVendorOwnerWithStore();
        $productB = Product::factory()->create(['vendor_id' => $vendorB->id, 'store_id' => $storeB->id]);
        $image = \App\Models\ProductImage::create(['product_id' => $productB->id, 'path' => 'product-images/fake.jpg', 'is_primary' => true]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($vendorA->owner)
            ->test('vendor.product-manager')
            ->call('deleteImage', $image->id);
    }
}
