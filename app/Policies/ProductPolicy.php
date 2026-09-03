<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_staff', 'vendor_owner', 'vendor_staff']);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->ownsProduct($user, $product);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['vendor_owner', 'vendor_staff']);
    }

    public function update(User $user, Product $product): bool
    {
        return $this->ownsProduct($user, $product) && $user->hasAnyRole(['vendor_owner', 'vendor_staff']);
    }

    public function delete(User $user, Product $product): bool
    {
        return $this->ownsProduct($user, $product) && $user->hasAnyRole(['vendor_owner', 'vendor_staff']);
    }

    private function ownsProduct(User $user, Product $product): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_staff'])
            || $user->vendor_id === $product->vendor_id;
    }
}
