<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentChunk>
 */
class DocumentChunkFactory extends Factory
{
    public function definition(): array
    {
        $content = fake()->paragraph();

        return [
            'vendor_id' => Vendor::factory(),
            'document_id' => Document::factory(),
            'chunk_index' => 0,
            'content' => $content,
            'token_count' => (int) (strlen($content) / 4),
            'qdrant_point_id' => null,
            'metadata' => null,
        ];
    }
}
