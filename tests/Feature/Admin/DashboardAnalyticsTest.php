<?php

namespace Tests\Feature\Admin;

use App\Models\Conversation;
use App\Models\Order;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPackage;
use App\Models\WebhookLog;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardAnalyticsTest extends TestCase
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

    public function test_platform_revenue_excludes_pending_and_cancelled_orders(): void
    {
        Order::factory()->create(['status' => 'completed', 'total' => 1000]);
        Order::factory()->create(['status' => 'pending', 'total' => 500]);
        Order::factory()->create(['status' => 'cancelled', 'total' => 300]);

        $html = Livewire::actingAs($this->admin())->test('admin.dashboard')->html();

        $this->assertStringContainsString('1,000.00', $html);
    }

    public function test_revenue_by_package_reflects_vendor_count_and_price(): void
    {
        $package = VendorPackage::factory()->create(['name' => 'Gold Plan', 'price' => 5000]);
        Vendor::factory()->create(['vendor_package_id' => $package->id]);
        Vendor::factory()->create(['vendor_package_id' => $package->id]);

        $html = Livewire::actingAs($this->admin())->test('admin.dashboard')->html();

        $this->assertStringContainsString('Gold Plan', $html);
        $this->assertStringContainsString('10,000.00', $html); // 2 vendors * 5000
    }

    public function test_webhook_failure_rate_calculated_correctly(): void
    {
        WebhookLog::factory()->count(3)->create(['processing_status' => 'processed']);
        WebhookLog::factory()->count(1)->create(['processing_status' => 'failed']);

        $html = Livewire::actingAs($this->admin())->test('admin.dashboard')->html();

        $this->assertStringContainsString('25%', $html);
    }

    public function test_conversations_needing_attention_counted(): void
    {
        Conversation::factory()->create(['status' => 'needs_attention']);
        Conversation::factory()->create(['status' => 'active']);

        $html = Livewire::actingAs($this->admin())->test('admin.dashboard')->html();

        $this->assertMatchesRegularExpression('/Needs attention.*?<p[^>]*>\s*1\s*<\/p>/s', $html);
    }

    public function test_vendor_cannot_access_admin_dashboard(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $vendor->owner->assignRole('vendor_owner');
        $vendor->owner->update(['vendor_id' => $vendor->id]);

        // admin.dashboard has no explicit role check inside mount(), but the
        // ROUTE middleware (role:super_admin|admin_staff) gates it — verify
        // via the real HTTP route, not a bare component test.
        $this->actingAs($vendor->owner)->get(route('admin.dashboard'))->assertForbidden();
    }
}
