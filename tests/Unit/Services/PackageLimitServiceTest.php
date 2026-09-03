<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPackage;
use App\Services\Vendor\PackageLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageLimitServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_within_staff_limit_can_add_more(): void
    {
        $package = VendorPackage::factory()->create(['max_staff_users' => 3]);
        $vendor = Vendor::factory()->create(['vendor_package_id' => $package->id]);

        $service = new PackageLimitService();

        $this->assertTrue($service->canAddStaffUser($vendor));
        $this->assertSame(3, $service->remainingStaffSlots($vendor));
    }

    public function test_vendor_at_staff_limit_cannot_add_more(): void
    {
        $package = VendorPackage::factory()->create(['max_staff_users' => 1]);
        $vendor = Vendor::factory()->create(['vendor_package_id' => $package->id]);

        // owner counts as staff via users.vendor_id
        User::factory()->create(['vendor_id' => $vendor->id]);

        $service = new PackageLimitService();

        $this->assertFalse($service->canAddStaffUser($vendor));
        $this->assertSame(0, $service->remainingStaffSlots($vendor));
    }

    public function test_unlimited_package_always_allows_more(): void
    {
        $package = VendorPackage::factory()->create(['max_staff_users' => -1]);
        $vendor = Vendor::factory()->create(['vendor_package_id' => $package->id]);

        User::factory()->count(50)->create(['vendor_id' => $vendor->id]);

        $service = new PackageLimitService();

        $this->assertTrue($service->canAddStaffUser($vendor));
    }
}
