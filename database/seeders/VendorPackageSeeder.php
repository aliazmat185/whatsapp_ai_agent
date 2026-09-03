<?php

namespace Database\Seeders;

use App\Models\VendorPackage;
use Illuminate\Database\Seeder;

class VendorPackageSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            [
                'name' => 'Basic',
                'slug' => 'basic',
                'price' => 2000,
                'billing_cycle' => 'monthly',
                'max_stores' => 1,
                'max_products' => 50,
                'max_staff_users' => 1,
                'max_whatsapp_numbers' => 1,
                'max_orders_per_month' => 200,
                'ai_features_enabled' => true,
                'analytics_access' => false,
                'enabled_payment_methods' => ['cod'],
            ],
            [
                'name' => 'Standard',
                'slug' => 'standard',
                'price' => 5000,
                'billing_cycle' => 'monthly',
                'max_stores' => 3,
                'max_products' => 500,
                'max_staff_users' => 5,
                'max_whatsapp_numbers' => 1,
                'max_orders_per_month' => 1000,
                'ai_features_enabled' => true,
                'analytics_access' => true,
                'enabled_payment_methods' => ['cod', 'jazzcash', 'easypaisa'],
            ],
            [
                'name' => 'Premium',
                'slug' => 'premium',
                'price' => 12000,
                'billing_cycle' => 'monthly',
                'max_stores' => -1,
                'max_products' => -1,
                'max_staff_users' => -1,
                'max_whatsapp_numbers' => 1,
                'max_orders_per_month' => -1,
                'ai_features_enabled' => true,
                'analytics_access' => true,
                'enabled_payment_methods' => ['cod', 'jazzcash', 'easypaisa', 'card'],
            ],
        ];

        foreach ($packages as $package) {
            VendorPackage::updateOrCreate(['slug' => $package['slug']], $package);
        }
    }
}
