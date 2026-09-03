<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPackage;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PackageManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_super_admin_can_create_a_package(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        Livewire::actingAs($admin)
            ->test('admin.package-manager')
            ->call('create')
            ->set('name', 'Gold')
            ->set('price', 7500)
            ->set('maxStores', 5)
            ->set('maxProducts', 300)
            ->set('maxStaffUsers', 3)
            ->set('enabledPaymentMethods', ['cod', 'jazzcash'])
            ->call('save');

        $this->assertDatabaseHas('vendor_packages', [
            'name' => 'Gold',
            'max_stores' => 5,
            'max_products' => 300,
        ]);
    }

    public function test_vendor_owner_cannot_manage_packages(): void
    {
        $vendor = Vendor::factory()->approved()->create();

        Livewire::actingAs($vendor->owner)
            ->test('admin.package-manager')
            ->assertForbidden();
    }

    public function test_admin_staff_cannot_create_package_but_can_view(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin_staff');

        Livewire::actingAs($admin)
            ->test('admin.package-manager')
            ->assertOk()
            ->call('create')
            ->assertForbidden();
    }

    public function test_retiring_a_package_toggles_is_active(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $package = VendorPackage::factory()->create(['is_active' => true]);

        Livewire::actingAs($admin)
            ->test('admin.package-manager')
            ->call('toggleActive', $package->id);

        $this->assertFalse($package->fresh()->is_active);
    }
}
