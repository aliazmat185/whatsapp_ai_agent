<?php

namespace App\Policies;

use App\Models\User;

class VendorPackagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_staff']);
    }

    public function manage(User $user): bool
    {
        return $user->hasRole('super_admin');
    }
}
