<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $store = Store::factory()->create();

        return [
            'vendor_id' => $store->vendor_id,
            'store_id' => $store->id,
            'name' => fake()->words(2, true),
            'slug' => Str::slug('product-'.Str::random(8)),
            'base_price' => fake()->randomFloat(2, 100, 5000),
            'is_active' => true,
        ];
    }
}
