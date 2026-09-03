<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'slug', 'price', 'billing_cycle',
    'max_stores', 'max_products', 'max_staff_users', 'max_whatsapp_numbers', 'max_orders_per_month',
    'ai_features_enabled', 'analytics_access', 'enabled_payment_methods', 'is_active',
])]
class VendorPackage extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'ai_features_enabled' => 'boolean',
            'analytics_access' => 'boolean',
            'enabled_payment_methods' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(PackageSubscription::class);
    }

    public function isUnlimited(string $limitField): bool
    {
        return (int) $this->{$limitField} === -1;
    }
}
