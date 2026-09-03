<?php

namespace Database\Factories;

use App\Models\KnowledgeRetrievalLog;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeRetrievalLog>
 */
class KnowledgeRetrievalLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'kind' => 'knowledge_chunk',
            'query' => fake()->sentence(),
            'results_count' => fake()->numberBetween(0, 5),
            'top_score' => fake()->randomFloat(4, 0, 1),
            'below_threshold' => false,
            'latency_ms' => fake()->numberBetween(50, 500),
        ];
    }
}
