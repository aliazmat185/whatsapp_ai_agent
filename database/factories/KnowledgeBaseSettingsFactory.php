<?php

namespace Database\Factories;

use App\Models\KnowledgeBaseSettings;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KnowledgeBaseSettings>
 */
class KnowledgeBaseSettingsFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'max_chunks_per_query' => 5,
            'similarity_threshold' => 0.750,
            'escalate_on_no_match' => true,
        ];
    }
}
