<?php

namespace Tests\Feature\Vendor;

use App\Models\Order;
use App\Models\Vendor;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function approvedVendorOwner(): Vendor
    {
        $vendor = Vendor::factory()->approved()->create();
        $vendor->owner->assignRole('vendor_owner');
        $vendor->owner->update(['vendor_id' => $vendor->id]);

        return $vendor;
    }

    public function test_vendor_sees_only_own_orders(): void
    {
        $vendorA = $this->approvedVendorOwner();
        $vendorB = $this->approvedVendorOwner();

        Order::factory()->forVendor($vendorA)->create(['order_number' => 'ORD-A']);
        Order::factory()->forVendor($vendorB)->create(['order_number' => 'ORD-B']);

        $component = Livewire::actingAs($vendorA->owner)->test('vendor.order-list');

        $this->assertStringContainsString('ORD-A', $component->html());
        $this->assertStringNotContainsString('ORD-B', $component->html());
    }

    public function test_vendor_cannot_view_another_vendors_order(): void
    {
        $vendorA = $this->approvedVendorOwner();
        $vendorB = $this->approvedVendorOwner();
        $order = Order::factory()->forVendor($vendorB)->create();

        Livewire::actingAs($vendorA->owner)
            ->test('vendor.order-detail', ['order' => $order])
            ->assertStatus(404);
    }

    public function test_vendor_can_transition_order_through_allowed_states(): void
    {
        $vendor = $this->approvedVendorOwner();
        $order = Order::factory()->forVendor($vendor)->create(['status' => 'pending']);

        Livewire::actingAs($vendor->owner)
            ->test('vendor.order-detail', ['order' => $order])
            ->call('transitionOrderStatus', 'confirmed');

        $this->assertSame('confirmed', $order->fresh()->status);
    }

    public function test_vendor_cannot_skip_states(): void
    {
        $vendor = $this->approvedVendorOwner();
        $order = Order::factory()->forVendor($vendor)->create(['status' => 'pending']);

        $this->expectException(\InvalidArgumentException::class);

        Livewire::actingAs($vendor->owner)
            ->test('vendor.order-detail', ['order' => $order])
            ->call('transitionOrderStatus', 'out_for_delivery');
    }

    public function test_order_list_filters_by_status(): void
    {
        $vendor = $this->approvedVendorOwner();
        Order::factory()->forVendor($vendor)->create(['order_number' => 'ORD-PENDING', 'status' => 'pending']);
        Order::factory()->forVendor($vendor)->create(['order_number' => 'ORD-DONE', 'status' => 'completed']);

        $component = Livewire::actingAs($vendor->owner)
            ->test('vendor.order-list')
            ->set('statusFilter', 'completed');

        $this->assertStringContainsString('ORD-DONE', $component->html());
        $this->assertStringNotContainsString('ORD-PENDING', $component->html());
    }
}
