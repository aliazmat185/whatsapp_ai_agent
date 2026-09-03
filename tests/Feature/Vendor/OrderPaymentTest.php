<?php

namespace Tests\Feature\Vendor;

use App\Models\Order;
use App\Models\Vendor;
use App\Services\Payment\PaymentService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderPaymentTest extends TestCase
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

    public function test_vendor_can_mark_cod_payment_as_received(): void
    {
        $vendor = $this->approvedVendorOwner();
        $order = Order::factory()->forVendor($vendor)->create(['payment_method' => 'cod', 'payment_status' => 'pending']);
        app(PaymentService::class)->initiateForOrder($order);

        Livewire::actingAs($vendor->owner)
            ->test('vendor.order-detail', ['order' => $order])
            ->call('markPaymentPaid');

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('paid', $order->payment->fresh()->status);
    }
}
