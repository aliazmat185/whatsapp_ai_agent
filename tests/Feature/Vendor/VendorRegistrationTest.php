<?php

namespace Tests\Feature\Vendor;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPackage;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VendorRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        VendorPackage::factory()->create(['slug' => 'basic', 'price' => 1000, 'is_active' => true]);
    }

    public function test_vendor_can_register_and_lands_pending(): void
    {
        Livewire::test('auth.vendor-register')
            ->set('businessName', 'Ali Store')
            ->set('ownerName', 'Ali Azmat')
            ->set('email', 'ali@example.com')
            ->set('phone', '+923001234567')
            ->set('whatsappNumber', '+923001234599')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register')
            ->assertRedirect(route('vendor.dashboard'));

        $vendor = Vendor::first();

        $this->assertNotNull($vendor);
        $this->assertSame('pending', $vendor->status);
        $this->assertSame('Ali Store', $vendor->business_name);
        $this->assertSame('+923001234599', $vendor->whatsapp_number);

        $user = User::where('email', 'ali@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('vendor_owner'));
        $this->assertSame($vendor->id, $user->vendor_id);

        $this->assertDatabaseHas('vendor_approvals', [
            'vendor_id' => $vendor->id,
            'action' => 'submitted',
        ]);
    }

    public function test_registration_requires_unique_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::test('auth.vendor-register')
            ->set('businessName', 'Ali Store')
            ->set('ownerName', 'Ali Azmat')
            ->set('email', 'taken@example.com')
            ->set('phone', '+923001234567')
            ->set('whatsappNumber', '+923001234599')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register')
            ->assertHasErrors(['email']);
    }

    public function test_pending_vendor_cannot_reach_admin_area(): void
    {
        Livewire::test('auth.vendor-register')
            ->set('businessName', 'Ali Store')
            ->set('ownerName', 'Ali Azmat')
            ->set('email', 'ali2@example.com')
            ->set('phone', '+923001234568')
            ->set('whatsappNumber', '+923001234598')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register');

        $this->get('/admin')->assertForbidden();
    }
}
