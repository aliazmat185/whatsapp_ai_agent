<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendorPackage;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        RateLimiter::clear('register|127.0.0.1');
    }

    public function test_login_locks_out_after_five_failed_attempts(): void
    {
        User::factory()->create(['email' => 'ali@example.com', 'password' => bcrypt('correct-password')]);

        $component = Livewire::test('auth.login');

        for ($i = 0; $i < 5; $i++) {
            $component->set('email', 'ali@example.com')->set('password', 'wrong')->call('login');
        }

        $component->set('email', 'ali@example.com')->set('password', 'wrong')->call('login');

        $component->assertHasErrors(['email']);
        $this->assertStringContainsString('Too many login attempts', $component->html());
    }

    public function test_registration_locks_out_after_five_attempts_from_same_ip(): void
    {
        VendorPackage::factory()->create(['is_active' => true]);

        for ($i = 0; $i < 5; $i++) {
            Livewire::test('auth.vendor-register')
                ->set('businessName', 'Shop')
                ->set('ownerName', 'Owner')
                ->set('email', "owner{$i}@example.com")
                ->set('phone', "+9230000000{$i}")
                ->set('password', 'password123')
                ->set('password_confirmation', 'password123')
                ->call('register');
        }

        $component = Livewire::test('auth.vendor-register')
            ->set('businessName', 'Shop Overflow')
            ->set('ownerName', 'Owner')
            ->set('email', 'overflow@example.com')
            ->set('phone', '+923000009999')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register');

        $component->assertHasErrors(['businessName']);
        $this->assertDatabaseMissing('users', ['email' => 'overflow@example.com']);
    }

    public function test_webhook_route_has_rate_limit_middleware_applied(): void
    {
        $route = collect(app('router')->getRoutes())->first(
            fn ($r) => $r->getName() === 'webhook.whatsapp.receive'
        );

        $this->assertNotNull($route);
        $this->assertTrue(
            collect($route->gatherMiddleware())->contains(fn ($m) => str_starts_with($m, 'throttle:60,1'))
        );
    }
}
