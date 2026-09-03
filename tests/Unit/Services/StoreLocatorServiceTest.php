<?php

namespace Tests\Unit\Services;

use App\Models\Store;
use App\Models\StoreLocation;
use App\Models\Vendor;
use App\Services\Store\StoreLocatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreLocatorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_nearest_store_is_ranked_first(): void
    {
        $vendor = Vendor::factory()->approved()->create();

        // Customer is at Saddar, Karachi (~24.86, 67.01)
        $near = Store::factory()->create(['vendor_id' => $vendor->id, 'name' => 'Near Branch']);
        StoreLocation::factory()->create(['store_id' => $near->id, 'latitude' => 24.861, 'longitude' => 67.011]);

        $far = Store::factory()->create(['vendor_id' => $vendor->id, 'name' => 'Far Branch']);
        StoreLocation::factory()->create(['store_id' => $far->id, 'latitude' => 25.40, 'longitude' => 68.35]); // Hyderabad, ~150km away

        $service = new StoreLocatorService();
        $results = $service->nearestStores($vendor->id, 24.86, 67.01, limit: 2);

        $this->assertCount(2, $results);
        $this->assertSame('Near Branch', $results->first()->name);
        $this->assertLessThan($results->last()->distance_km, $results->first()->distance_km);
    }

    public function test_inactive_stores_are_excluded(): void
    {
        $vendor = Vendor::factory()->approved()->create();

        $inactive = Store::factory()->create(['vendor_id' => $vendor->id, 'is_active' => false]);
        StoreLocation::factory()->create(['store_id' => $inactive->id, 'latitude' => 24.86, 'longitude' => 67.01]);

        $service = new StoreLocatorService();
        $results = $service->nearestStores($vendor->id, 24.86, 67.01);

        $this->assertCount(0, $results);
    }

    public function test_single_store_vendor_resolves_without_location(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        StoreLocation::factory()->create(['store_id' => $store->id]);

        $service = new StoreLocatorService();
        $resolved = $service->resolveStoreForCustomer($vendor->id, null, null);

        $this->assertSame($store->id, $resolved->id);
    }

    public function test_multi_store_vendor_without_location_returns_null(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        Store::factory()->count(2)->create(['vendor_id' => $vendor->id])
            ->each(fn ($store) => StoreLocation::factory()->create(['store_id' => $store->id]));

        $service = new StoreLocatorService();
        $resolved = $service->resolveStoreForCustomer($vendor->id, null, null);

        $this->assertNull($resolved);
    }

    public function test_falls_back_to_primary_store_when_none_within_radius(): void
    {
        config(['commerce.store_search_radius_km' => 5]);

        $vendor = Vendor::factory()->approved()->create();
        $primary = Store::factory()->create(['vendor_id' => $vendor->id, 'is_primary' => true]);
        StoreLocation::factory()->create(['store_id' => $primary->id, 'latitude' => 25.40, 'longitude' => 68.35]);

        $other = Store::factory()->create(['vendor_id' => $vendor->id, 'is_primary' => false]);
        StoreLocation::factory()->create(['store_id' => $other->id, 'latitude' => 25.42, 'longitude' => 68.37]);

        $service = new StoreLocatorService();
        // Customer far from both stores (Karachi vs Hyderabad-area stores).
        $resolved = $service->resolveStoreForCustomer($vendor->id, 24.86, 67.01);

        $this->assertNotNull($resolved);
    }
}
