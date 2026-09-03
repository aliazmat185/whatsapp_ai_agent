<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Auto-scopes queries to the authenticated user's vendor_id when a
 * vendor_owner/vendor_staff is logged in. Super admins bypass the scope.
 * Used by every vendor-owned model (stores, products, orders, ...).
 */
trait BelongsToVendor
{
    protected static function bootBelongsToVendor(): void
    {
        static::addGlobalScope('vendor', function (Builder $builder) {
            $user = Auth::user();

            if ($user && $user->vendor_id) {
                $builder->where($builder->getModel()->getTable().'.vendor_id', $user->vendor_id);
            }
        });

        static::creating(function ($model) {
            $user = Auth::user();

            if ($user && $user->vendor_id && ! $model->vendor_id) {
                $model->vendor_id = $user->vendor_id;
            }
        });
    }
}
