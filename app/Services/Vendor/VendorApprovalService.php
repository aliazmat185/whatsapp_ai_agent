<?php

namespace App\Services\Vendor;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorApproval;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Owns every vendor.status transition and its audit trail row
 * (vendor_approvals). See PLAN.md §9 Admin panel / §13 Validation Rules.
 */
class VendorApprovalService
{
    public function approve(Vendor $vendor, User $admin): Vendor
    {
        if ($vendor->status === 'approved') {
            return $vendor;
        }

        return DB::transaction(function () use ($vendor, $admin) {
            $vendor->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $admin->id,
                'rejection_reason' => null,
            ]);

            VendorApproval::create([
                'vendor_id' => $vendor->id,
                'action' => 'approved',
                'performed_by' => $admin->id,
            ]);

            return $vendor->fresh();
        });
    }

    public function reject(Vendor $vendor, User $admin, string $reason): Vendor
    {
        $this->requireReason($reason);

        return DB::transaction(function () use ($vendor, $admin, $reason) {
            $vendor->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
            ]);

            VendorApproval::create([
                'vendor_id' => $vendor->id,
                'action' => 'rejected',
                'performed_by' => $admin->id,
                'reason' => $reason,
            ]);

            return $vendor->fresh();
        });
    }

    public function suspend(Vendor $vendor, User $admin, string $reason): Vendor
    {
        $this->requireReason($reason);

        return DB::transaction(function () use ($vendor, $admin, $reason) {
            $vendor->update([
                'status' => 'suspended',
                'suspended_reason' => $reason,
            ]);

            VendorApproval::create([
                'vendor_id' => $vendor->id,
                'action' => 'suspended',
                'performed_by' => $admin->id,
                'reason' => $reason,
            ]);

            return $vendor->fresh();
        });
    }

    public function reactivate(Vendor $vendor, User $admin): Vendor
    {
        return DB::transaction(function () use ($vendor, $admin) {
            $vendor->update([
                'status' => 'approved',
                'suspended_reason' => null,
            ]);

            VendorApproval::create([
                'vendor_id' => $vendor->id,
                'action' => 'reactivated',
                'performed_by' => $admin->id,
            ]);

            return $vendor->fresh();
        });
    }

    private function requireReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reason is required for this action.');
        }
    }
}
