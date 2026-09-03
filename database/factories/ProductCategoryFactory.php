<?php

namespace Database\Factories;

use App\Models\ProductCategory;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductCategory>
 */
class ProductCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'name' => fake()->word(),
            'slug' => Str::slug('category-'.Str::random(8)),
            'is_active' => true,
        ];
    }
}
