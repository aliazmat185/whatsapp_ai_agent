<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPackage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'owner_user_id' => User::factory(),
            'business_name' => fake()->company(),
            'vendor_package_id' => VendorPackage::factory(),
            'status' => 'pending',
            'default_currency' => 'PKR',
            'timezone' => 'Asia/Karachi',
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }
}
