<?php

namespace Tests\Feature\Vendor;

use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Vendor;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
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

    public function test_dashboard_counts_todays_orders_only(): void
    {
        $vendor = $this->approvedVendorOwner();
        Order::factory()->forVendor($vendor)->create(['created_at' => now()]);
        Order::factory()->forVendor($vendor)->create(['created_at' => now()->subDays(2)]);

        $html = Livewire::actingAs($vendor->owner)->test('vendor.dashboard')->html();

        // "Today's orders" widget should show 1, not 2.
        $this->assertMatchesRegularExpression('/Today(?:\'|&#0?39;)s orders.*?<p[^>]*>\s*1\s*<\/p>/s', $html);
    }

    public function test_dashboard_counts_pending_orders_across_all_days(): void
    {
        $vendor = $this->approvedVendorOwner();
        Order::factory()->forVendor($vendor)->create(['status' => 'pending', 'created_at' => now()->subDays(5)]);
        Order::factory()->forVendor($vendor)->create(['status' => 'completed', 'created_at' => now()]);

        $html = Livewire::actingAs($vendor->owner)->test('vendor.dashboard')->html();

        $this->assertMatchesRegularExpression('/Pending orders.*?<p[^>]*>\s*1\s*<\/p>/s', $html);
    }

    public function test_todays_sales_excludes_pending_and_cancelled_orders(): void
    {
        $vendor = $this->approvedVendorOwner();
        Order::factory()->forVendor($vendor)->create(['status' => 'completed', 'total' => 1000, 'created_at' => now()]);
        Order::factory()->forVendor($vendor)->create(['status' => 'pending', 'total' => 500, 'created_at' => now()]);
        Order::factory()->forVendor($vendor)->create(['status' => 'cancelled', 'total' => 300, 'created_at' => now()]);

        $html = Livewire::actingAs($vendor->owner)->test('vendor.dashboard')->html();

        $this->assertStringContainsString('1,000.00', $html);
        $this->assertStringNotContainsString('1,800.00', $html);
    }

    public function test_low_stock_count_reflects_inventory_at_or_below_threshold(): void
    {
        $vendor = $this->approvedVendorOwner();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $lowProduct = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $okProduct = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        Inventory::create(['store_id' => $store->id, 'product_id' => $lowProduct->id, 'quantity' => 2, 'low_stock_threshold' => 5]);
        Inventory::create(['store_id' => $store->id, 'product_id' => $okProduct->id, 'quantity' => 50, 'low_stock_threshold' => 5]);

        $html = Livewire::actingAs($vendor->owner)->test('vendor.dashboard')->html();

        $this->assertMatchesRegularExpression('/Low stock items.*?<p[^>]*>\s*1\s*<\/p>/s', $html);
    }
}
