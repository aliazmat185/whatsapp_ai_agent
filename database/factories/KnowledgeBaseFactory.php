<?php

namespace Database\Factories;

use App\Models\KnowledgeBase;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeBase>
 */
class KnowledgeBaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'name' => fake()->words(2, true),
            'type' => 'documents',
            'is_active' => true,
        ];
    }
}
