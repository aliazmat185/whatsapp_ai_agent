<?php

namespace App\Services\Store;

use App\Models\Store;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds a vendor's nearest active stores to a customer lat/lng.
 * See PLAN.md §7 Store and Product Selection Flow.
 *
 * MySQL uses ST_Distance_Sphere against the generated+spatially-indexed
 * `point` column (see store_locations migration). sqlite (test env) has no
 * spatial support, so it falls back to a PHP Haversine calculation — fine
 * at test-fixture scale, never used in production.
 */
class StoreLocatorService
{
    public function nearestStores(int $vendorId, float $lat, float $lng, int $limit = 3): Collection
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            return $this->nearestStoresMysql($vendorId, $lat, $lng, $limit);
        }

        return $this->nearestStoresFallback($vendorId, $lat, $lng, $limit);
    }

    /**
     * Nearest store within the configured search radius, or the vendor's
     * primary store if none are within range (PLAN.md §7 fallback rule).
     */
    public function resolveStoreForCustomer(int $vendorId, ?float $lat, ?float $lng): ?Store
    {
        $activeStores = Store::withoutGlobalScope('vendor')
            ->where('vendor_id', $vendorId)
            ->where('is_active', true);

        if ($activeStores->clone()->count() <= 1) {
            return $activeStores->first();
        }

        if ($lat === null || $lng === null) {
            return null; // caller should ask the customer for their location
        }

        $nearest = $this->nearestStores($vendorId, $lat, $lng, 1)->first();
        $radiusKm = $nearest?->location?->delivery_radius_km !== null
            ? (float) $nearest->location->delivery_radius_km
            : (float) config('commerce.store_search_radius_km');

        if ($nearest && $nearest->distance_km <= $radiusKm) {
            return $nearest;
        }

        // No store within radius — fall back to the closest one anyway,
        // or the vendor's designated primary store if distance is unknown.
        return $nearest ?? $activeStores->where('is_primary', true)->first() ?? $activeStores->first();
    }

    private function nearestStoresMysql(int $vendorId, float $lat, float $lng, int $limit): Collection
    {
        $stores = Store::withoutGlobalScope('vendor')
            ->select('stores.*')
            ->selectRaw(
                'ST_Distance_Sphere(store_locations.point, ST_SRID(POINT(?, ?), 4326)) / 1000 AS distance_km',
                [$lng, $lat]
            )
            ->join('store_locations', 'store_locations.store_id', '=', 'stores.id')
            ->where('stores.vendor_id', $vendorId)
            ->where('stores.is_active', true)
            ->orderBy('distance_km')
            ->limit($limit)
            ->get();

        $stores->each(fn ($store) => $store->distance_km = round((float) $store->distance_km, 2));

        return $stores;
    }

    private function nearestStoresFallback(int $vendorId, float $lat, float $lng, int $limit): Collection
    {
        $stores = Store::withoutGlobalScope('vendor')
            ->with('location')
            ->where('vendor_id', $vendorId)
            ->where('is_active', true)
            ->get()
            ->filter(fn ($store) => $store->location !== null)
            ->map(function ($store) use ($lat, $lng) {
                $store->distance_km = round($this->haversineKm(
                    $lat, $lng, (float) $store->location->latitude, (float) $store->location->longitude
                ), 2);

                return $store;
            })
            ->sortBy('distance_km')
            ->take($limit)
            ->values();

        return new Collection($stores->all());
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }
}
