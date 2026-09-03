<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;

class StorePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_staff', 'vendor_owner', 'vendor_staff']);
    }

    public function view(User $user, Store $store): bool
    {
        return $this->ownsStore($user, $store);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('vendor_owner');
    }

    public function update(User $user, Store $store): bool
    {
        return $this->ownsStore($user, $store) && $user->hasAnyRole(['vendor_owner', 'vendor_staff']);
    }

    public function delete(User $user, Store $store): bool
    {
        return $this->ownsStore($user, $store) && $user->hasRole('vendor_owner');
    }

    private function ownsStore(User $user, Store $store): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_staff'])
            || $user->vendor_id === $store->vendor_id;
    }
}
