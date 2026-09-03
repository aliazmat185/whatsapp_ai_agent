<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vendor;

class VendorPolicy
{
    /**
     * Admin-only: list/browse all vendors.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_staff']);
    }

    /**
     * Admin can view any vendor. A vendor user can view its own record.
     */
    public function view(User $user, Vendor $vendor): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_staff'])
            || $user->vendor_id === $vendor->id;
    }

    public function update(User $user, Vendor $vendor): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_staff'])
            || ($user->vendor_id === $vendor->id && $user->hasRole('vendor_owner'));
    }

    /**
     * Approve/reject/suspend/reactivate — admin only.
     */
    public function manageApproval(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_staff']);
    }

    public function delete(User $user, Vendor $vendor): bool
    {
        return $user->hasRole('super_admin');
    }
}
