<?php

namespace Tests\Feature\Vendor;

use App\Models\Notification;
use App\Models\Vendor;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationDismissTest extends TestCase
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

    public function test_dismissing_notification_marks_it_read(): void
    {
        $vendor = $this->approvedVendorOwner();
        $notification = Notification::create([
            'notifiable_type' => Vendor::class,
            'notifiable_id' => $vendor->id,
            'type' => 'new_order',
            'channel' => 'dashboard',
            'payload' => ['order_number' => 'ORD-TEST'],
        ]);

        Livewire::actingAs($vendor->owner)
            ->test('vendor.dashboard')
            ->call('dismissNotification', $notification->id);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_vendor_cannot_dismiss_another_vendors_notification(): void
    {
        $vendorA = $this->approvedVendorOwner();
        $vendorB = $this->approvedVendorOwner();
        $notification = Notification::create([
            'notifiable_type' => Vendor::class,
            'notifiable_id' => $vendorB->id,
            'type' => 'new_order',
            'channel' => 'dashboard',
            'payload' => [],
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::actingAs($vendorA->owner)
            ->test('vendor.dashboard')
            ->call('dismissNotification', $notification->id);
    }

    public function test_whatsapp_channel_notification_not_shown_as_activity_item(): void
    {
        $vendor = $this->approvedVendorOwner();
        Notification::create([
            'notifiable_type' => Vendor::class,
            'notifiable_id' => $vendor->id,
            'type' => 'new_order',
            'channel' => 'whatsapp',
            'payload' => ['order_number' => 'ORD-WA-ONLY'],
        ]);

        $html = Livewire::actingAs($vendor->owner)->test('vendor.dashboard')->html();

        $this->assertStringNotContainsString('ORD-WA-ONLY', $html);
    }
}
