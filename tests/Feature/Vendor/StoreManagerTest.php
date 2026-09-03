<?php

namespace Tests\Feature\Vendor;

use App\Models\Store;
use App\Models\Vendor;
use App\Models\VendorPackage;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StoreManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function approvedVendorOwner(int $maxStores = 2): Vendor
    {
        $package = VendorPackage::factory()->create(['max_stores' => $maxStores]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id]);
        $vendor->owner->assignRole('vendor_owner');
        $vendor->owner->update(['vendor_id' => $vendor->id]);

        return $vendor;
    }

    public function test_vendor_sees_only_their_own_stores(): void
    {
        $vendor = $this->approvedVendorOwner();
        $ownStore = Store::factory()->create(['vendor_id' => $vendor->id, 'name' => 'My Branch']);
        $otherVendor = $this->approvedVendorOwner();
        Store::factory()->create(['vendor_id' => $otherVendor->id, 'name' => 'Other Branch']);

        Livewire::actingAs($vendor->owner)
            ->test('vendor.store-manager')
            ->assertSee('My Branch')
            ->assertDontSee('Other Branch');
    }

    public function test_vendor_can_toggle_store_active_status(): void
    {
        $vendor = $this->approvedVendorOwner();
        $store = Store::factory()->create(['vendor_id' => $vendor->id, 'is_active' => true]);

        Livewire::actingAs($vendor->owner)
            ->test('vendor.store-manager')
            ->call('toggleActive', $store->id);

        $this->assertFalse($store->fresh()->is_active);
    }

    public function test_vendor_cannot_toggle_another_vendors_store(): void
    {
        $vendorA = $this->approvedVendorOwner();
        $vendorB = $this->approvedVendorOwner();
        $storeB = Store::factory()->create(['vendor_id' => $vendorB->id]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::actingAs($vendorA->owner)
            ->test('vendor.store-manager')
            ->call('toggleActive', $storeB->id);
    }
}
