<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'knowledge_base_id' => KnowledgeBase::factory(),
            'original_filename' => fake()->word().'.pdf',
            'disk_path' => 'knowledge-base/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(1024, 5_000_000),
            'source_hash' => hash('sha256', fake()->unique()->uuid()),
            'status' => 'pending',
        ];
    }
}
