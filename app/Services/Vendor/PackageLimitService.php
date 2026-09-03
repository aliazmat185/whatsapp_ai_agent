<?php

namespace App\Services\Vendor;

use App\Models\Vendor;
use App\Models\WhatsappAccount;

/**
 * Enforces vendor_packages limits at write-time (PLAN.md §13 Validation Rules
 * — "checked at write, not just UI"). Each check returns whether ONE MORE of
 * that resource may be created; -1 on the package column means unlimited.
 */
class PackageLimitService
{
    /**
     * Every vendor gets exactly one whatsapp_accounts row (decision-8/A1 —
     * one number per vendor, not per store), enforced by a DB unique
     * constraint on vendor_id. max_whatsapp_numbers on the package is really
     * an on/off gate for whether this package tier includes WhatsApp at all.
     */
    public function canConnectWhatsapp(Vendor $vendor): bool
    {
        if ((int) $vendor->package->max_whatsapp_numbers < 1) {
            return false;
        }

        $account = WhatsappAccount::where('vendor_id', $vendor->id)->first();

        return $account === null || $account->status !== 'active';
    }

    public function canAddStaffUser(Vendor $vendor): bool
    {
        return $this->withinLimit($vendor, 'max_staff_users', $vendor->staff()->count());
    }

    public function remainingStaffSlots(Vendor $vendor): int
    {
        return $this->remaining($vendor, 'max_staff_users', $vendor->staff()->count());
    }

    public function canAddStore(Vendor $vendor): bool
    {
        return $this->withinLimit($vendor, 'max_stores', $vendor->stores()->count());
    }

    public function remainingStoreSlots(Vendor $vendor): int
    {
        return $this->remaining($vendor, 'max_stores', $vendor->stores()->count());
    }

    public function canAddProduct(Vendor $vendor): bool
    {
        return $this->withinLimit($vendor, 'max_products', $vendor->products()->count());
    }

    public function remainingProductSlots(Vendor $vendor): int
    {
        return $this->remaining($vendor, 'max_products', $vendor->products()->count());
    }

    private function withinLimit(Vendor $vendor, string $limitField, int $currentCount): bool
    {
        $limit = (int) $vendor->package->{$limitField};

        if ($limit === -1) {
            return true;
        }

        return $currentCount < $limit;
    }

    private function remaining(Vendor $vendor, string $limitField, int $currentCount): int
    {
        $limit = (int) $vendor->package->{$limitField};

        if ($limit === -1) {
            return PHP_INT_MAX;
        }

        return max(0, $limit - $currentCount);
    }
}
