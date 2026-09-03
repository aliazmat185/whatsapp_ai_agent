<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\StoreLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreLocation>
 */
class StoreLocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'address_line' => fake()->streetAddress(),
            'city' => 'Karachi',
            // Default around Karachi so distance-based tests have realistic deltas.
            'latitude' => fake()->latitude(24.80, 24.95),
            'longitude' => fake()->longitude(66.95, 67.15),
        ];
    }
}
