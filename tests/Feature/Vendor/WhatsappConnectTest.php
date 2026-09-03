<?php

namespace Tests\Feature\Vendor;

use App\Models\Vendor;
use App\Models\VendorPackage;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class WhatsappConnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function approvedVendor(int $maxWhatsappNumbers = 1): Vendor
    {
        $package = VendorPackage::factory()->create(['max_whatsapp_numbers' => $maxWhatsappNumbers]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id]);
        $vendor->owner->assignRole('vendor_owner');
        $vendor->owner->update(['vendor_id' => $vendor->id]);

        return $vendor;
    }

    public function test_vendor_can_submit_number_and_it_registers_under_platform_waba(): void
    {
        Http::fake([
            '*/phone_numbers' => Http::response(['id' => 'meta-phone-id-123'], 200),
        ]);

        $vendor = $this->approvedVendor();

        Livewire::actingAs($vendor->owner)
            ->test('vendor.whatsapp-connect')
            ->set('phoneNumber', '+923001112222')
            ->call('submitNumber');

        $this->assertDatabaseHas('whatsapp_accounts', [
            'vendor_id' => $vendor->id,
            'phone_number_id' => 'meta-phone-id-123',
            'onboarding_status' => 'verifying',
        ]);
    }

    public function test_failed_registration_records_rejection_reason(): void
    {
        Http::fake([
            '*/phone_numbers' => Http::response(['error' => ['message' => 'Number already registered on WhatsApp']], 400),
        ]);

        $vendor = $this->approvedVendor();

        Livewire::actingAs($vendor->owner)
            ->test('vendor.whatsapp-connect')
            ->set('phoneNumber', '+923001112222')
            ->call('submitNumber');

        $this->assertDatabaseHas('whatsapp_accounts', [
            'vendor_id' => $vendor->id,
            'onboarding_status' => 'failed',
        ]);

        $account = $vendor->whatsappAccount()->first();
        $this->assertStringContainsString('Delete my account', $account->rejection_reason);
    }

    public function test_full_otp_flow_activates_account(): void
    {
        Http::fake([
            '*/phone_numbers' => Http::response(['id' => 'meta-phone-id-123'], 200),
            '*/request_code' => Http::response(['success' => true], 200),
            '*/verify_code' => Http::response(['success' => true], 200),
            '*/register' => Http::response(['success' => true], 200),
        ]);

        $vendor = $this->approvedVendor();

        $component = Livewire::actingAs($vendor->owner)
            ->test('vendor.whatsapp-connect')
            ->set('phoneNumber', '+923001112222')
            ->call('submitNumber')
            ->set('otpMethod', 'sms')
            ->call('sendOtp')
            ->set('otpCode', '123456')
            ->call('verifyCode');

        $this->assertDatabaseHas('whatsapp_accounts', [
            'vendor_id' => $vendor->id,
            'onboarding_status' => 'active',
            'status' => 'active',
        ]);
    }

    public function test_vendor_without_whatsapp_package_cannot_connect(): void
    {
        $vendor = $this->approvedVendor(maxWhatsappNumbers: 0);

        Livewire::actingAs($vendor->owner)
            ->test('vendor.whatsapp-connect')
            ->set('phoneNumber', '+923001112222')
            ->call('submitNumber')
            ->assertHasErrors(['phoneNumber']);

        $this->assertDatabaseCount('whatsapp_accounts', 0);
    }

    public function test_unapproved_vendor_cannot_access_whatsapp_wizard(): void
    {
        $vendor = Vendor::factory()->create(['status' => 'pending']);
        $vendor->owner->assignRole('vendor_owner');
        $vendor->owner->update(['vendor_id' => $vendor->id]);

        Livewire::actingAs($vendor->owner)
            ->test('vendor.whatsapp-connect')
            ->assertForbidden();
    }
}
