<?php

namespace Tests\Feature\Vendor;

use App\Models\Store;
use App\Models\Vendor;
use App\Models\VendorPackage;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StoreFormTest extends TestCase
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

    public function test_approved_vendor_can_create_a_store(): void
    {
        $vendor = $this->approvedVendorOwner();

        Livewire::actingAs($vendor->owner)
            ->test('vendor.store-form')
            ->set('name', 'Main Branch')
            ->set('addressLine', '123 Main St')
            ->set('city', 'Karachi')
            ->set('latitude', 24.86)
            ->set('longitude', 67.01)
            ->call('save');

        $this->assertDatabaseHas('stores', ['vendor_id' => $vendor->id, 'name' => 'Main Branch']);
        $this->assertDatabaseHas('store_locations', ['city' => 'Karachi']);
    }

    public function test_unapproved_vendor_cannot_create_store(): void
    {
        $vendor = Vendor::factory()->create(['status' => 'pending']);
        $vendor->owner->assignRole('vendor_owner');
        $vendor->owner->update(['vendor_id' => $vendor->id]);

        Livewire::actingAs($vendor->owner)
            ->test('vendor.store-form')
            ->assertForbidden();
    }

    public function test_vendor_cannot_exceed_store_package_limit(): void
    {
        $vendor = $this->approvedVendorOwner(maxStores: 1);
        Store::factory()->create(['vendor_id' => $vendor->id]);

        Livewire::actingAs($vendor->owner)
            ->test('vendor.store-form')
            ->assertRedirect(route('vendor.stores'));

        $this->assertSame(1, Store::where('vendor_id', $vendor->id)->count());
    }

    public function test_vendor_cannot_edit_another_vendors_store(): void
    {
        $vendorA = $this->approvedVendorOwner();
        $vendorB = $this->approvedVendorOwner();
        $storeB = Store::factory()->create(['vendor_id' => $vendorB->id]);

        Livewire::actingAs($vendorA->owner)
            ->test('vendor.store-form', ['store' => $storeB])
            ->assertForbidden();
    }

    public function test_only_one_store_can_be_primary(): void
    {
        $vendor = $this->approvedVendorOwner(maxStores: 3);
        $existingPrimary = Store::factory()->create(['vendor_id' => $vendor->id, 'is_primary' => true]);

        Livewire::actingAs($vendor->owner)
            ->test('vendor.store-form')
            ->set('name', 'Second Branch')
            ->set('addressLine', 'Somewhere')
            ->set('city', 'Karachi')
            ->set('latitude', 24.9)
            ->set('longitude', 67.05)
            ->set('isPrimary', true)
            ->call('save');

        $this->assertFalse($existingPrimary->fresh()->is_primary);
        $this->assertTrue(Store::where('name', 'Second Branch')->first()->is_primary);
    }

    public function test_editing_a_store_prefills_existing_values(): void
    {
        $vendor = $this->approvedVendorOwner();
        $store = Store::factory()->create(['vendor_id' => $vendor->id, 'name' => 'Existing Branch']);
        $store->location()->create([
            'address_line' => 'Old Address',
            'city' => 'Lahore',
            'latitude' => 31.5,
            'longitude' => 74.3,
            'delivery_radius_km' => 7.5,
        ]);

        Livewire::actingAs($vendor->owner)
            ->test('vendor.store-form', ['store' => $store])
            ->assertSet('name', 'Existing Branch')
            ->assertSet('city', 'Lahore')
            ->assertSet('deliveryRadiusKm', 7.5);
    }
}
