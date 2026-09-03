<?php

namespace App\Services\Commerce;

use App\Models\Store;
use Illuminate\Support\Str;

/**
 * Checks whether a store delivers to a given city, using the store's own
 * StoreLocation.city — no hardcoded city list. If a store has no location
 * configured at all, delivery is treated as available (nothing to enforce
 * against yet), so existing stores/tests that never set one up are
 * unaffected. See ToolRegistry::startCheckout() for where this gates order
 * creation.
 */
class DeliveryAvailabilityService
{
    /**
     * @return array{available: bool, store_city: ?string, delivery_fee: float}
     */
    public function check(Store $store, string $city): array
    {
        $store->loadMissing('location', 'vendor');

        $storeCity = trim((string) ($store->location?->city ?? ''));
        $givenCity = trim($city);

        if ($storeCity === '') {
            return ['available' => true, 'store_city' => null, 'delivery_fee' => $this->flatFee($store)];
        }

        $available = $givenCity !== '' && (
            Str::contains(Str::lower($storeCity), Str::lower($givenCity))
            || Str::contains(Str::lower($givenCity), Str::lower($storeCity))
        );

        return [
            'available' => $available,
            'store_city' => $storeCity,
            'delivery_fee' => $available ? $this->flatFee($store) : 0.0,
        ];
    }

    /**
     * A vendor's own delivery_fee overrides the platform default — null
     * means the vendor hasn't set one, so existing vendors keep today's
     * flat platform-wide behavior until they configure their own.
     */
    private function flatFee(Store $store): float
    {
        return (float) ($store->vendor->delivery_fee ?? config('commerce.default_delivery_fee', 0));
    }
}
