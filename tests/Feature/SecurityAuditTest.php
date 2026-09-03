<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * PLAN.md §15 Security — systematic sweep so a future route added under
 * admin/vendor prefixes without the right middleware fails CI immediately,
 * instead of silently shipping an unauthenticated/unauthorized endpoint.
 */
class SecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_admin_route_requires_auth_and_admin_role(): void
    {
        $adminRoutes = collect(RouteFacade::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'admin') && $route->getName() !== null);

        $this->assertGreaterThan(0, $adminRoutes->count(), 'No admin routes found — route list may have changed shape.');

        foreach ($adminRoutes as $route) {
            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth', $middleware, "Route [{$route->getName()}] is missing 'auth' middleware.");
            $this->assertTrue(
                collect($middleware)->contains(fn ($m) => str_starts_with($m, 'role:') && str_contains($m, 'super_admin')),
                "Route [{$route->getName()}] is missing an admin role check."
            );
        }
    }

    public function test_every_vendor_route_requires_auth_and_vendor_role(): void
    {
        $vendorRoutes = collect(RouteFacade::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'vendor') && $route->getName() !== null
                && $route->getName() !== 'vendor.register');

        $this->assertGreaterThan(0, $vendorRoutes->count(), 'No vendor routes found — route list may have changed shape.');

        foreach ($vendorRoutes as $route) {
            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth', $middleware, "Route [{$route->getName()}] is missing 'auth' middleware.");
            $this->assertTrue(
                collect($middleware)->contains(fn ($m) => str_starts_with($m, 'role:') && str_contains($m, 'vendor_owner')),
                "Route [{$route->getName()}] is missing a vendor role check."
            );
        }
    }

    public function test_webhook_receive_route_has_signature_verification_middleware(): void
    {
        $route = collect(RouteFacade::getRoutes())->first(fn ($r) => $r->getName() === 'webhook.whatsapp.receive');

        $this->assertNotNull($route);
        $this->assertContains('whatsapp.signature', $route->gatherMiddleware());
    }

    public function test_webhook_verify_route_has_no_signature_requirement(): void
    {
        // GET handshake carries no body to sign — it must rely on the
        // verify_token query param instead, not the signature middleware.
        $route = collect(RouteFacade::getRoutes())->first(fn ($r) => $r->getName() === 'webhook.whatsapp.verify');

        $this->assertNotNull($route);
        $this->assertNotContains('whatsapp.signature', $route->gatherMiddleware());
    }
}
