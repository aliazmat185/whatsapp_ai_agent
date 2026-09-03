<?php

namespace Database\Factories;

use App\Models\VendorPackage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<VendorPackage>
 */
class VendorPackageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Basic',
            'slug' => Str::slug('package-'.Str::random(8)),
            'price' => 1000,
            'billing_cycle' => 'monthly',
            'max_stores' => 1,
            'max_products' => 50,
            'max_staff_users' => 1,
            'max_whatsapp_numbers' => 1,
            'max_orders_per_month' => 100,
            'ai_features_enabled' => true,
            'analytics_access' => false,
            'enabled_payment_methods' => ['cod'],
            'is_active' => true,
        ];
    }
}
