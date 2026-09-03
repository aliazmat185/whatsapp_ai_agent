<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPackage;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VendorApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        return $admin;
    }

    public function test_admin_can_approve_pending_vendor(): void
    {
        $vendor = Vendor::factory()->create(['status' => 'pending']);

        Livewire::actingAs($this->admin())
            ->test('admin.vendor-list')
            ->call('approve', $vendor->id);

        $this->assertSame('approved', $vendor->fresh()->status);
        $this->assertDatabaseHas('vendor_approvals', [
            'vendor_id' => $vendor->id,
            'action' => 'approved',
        ]);
    }

    public function test_admin_can_reject_with_reason(): void
    {
        $vendor = Vendor::factory()->create(['status' => 'pending']);

        Livewire::actingAs($this->admin())
            ->test('admin.vendor-list')
            ->call('startReject', $vendor->id)
            ->set('reason', 'Invalid business documents')
            ->call('confirmReject');

        $vendor->refresh();
        $this->assertSame('rejected', $vendor->status);
        $this->assertSame('Invalid business documents', $vendor->rejection_reason);
    }

    public function test_reject_requires_a_reason(): void
    {
        $vendor = Vendor::factory()->create(['status' => 'pending']);

        Livewire::actingAs($this->admin())
            ->test('admin.vendor-list')
            ->call('startReject', $vendor->id)
            ->set('reason', '')
            ->call('confirmReject')
            ->assertHasErrors(['reason']);

        $this->assertSame('pending', $vendor->fresh()->status);
    }

    public function test_vendor_owner_cannot_access_vendor_list_component(): void
    {
        $vendor = Vendor::factory()->approved()->create();

        Livewire::actingAs($vendor->owner)
            ->test('admin.vendor-list')
            ->assertForbidden();
    }

    public function test_suspended_vendor_can_be_reactivated(): void
    {
        $vendor = Vendor::factory()->create(['status' => 'suspended', 'suspended_reason' => 'Policy violation']);

        Livewire::actingAs($this->admin())
            ->test('admin.vendor-list')
            ->call('reactivate', $vendor->id);

        $vendor->refresh();
        $this->assertSame('approved', $vendor->status);
        $this->assertNull($vendor->suspended_reason);
    }
}
