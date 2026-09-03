<?php

namespace App\AI\Retrieval;

use App\AI\Retrieval\Contracts\VectorStoreContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Single Qdrant collection shared by all vendors (RAG_PLAN.md — payload-filter
 * multi-tenancy, chosen over collection-per-vendor for thousands-of-vendors
 * scale). Every point payload carries vendor_id; every search() call filters
 * on it server-side so a caller cannot forget the scope.
 */
class QdrantVectorStore implements VectorStoreContract
{
    private function http()
    {
        $client = Http::baseUrl(config('qdrant.base_url'))->timeout(15);

        if (config('qdrant.api_key')) {
            $client = $client->withHeaders(['api-key' => config('qdrant.api_key')]);
        }

        return $client;
    }

    public function ensureCollection(): void
    {
        $collection = config('qdrant.collection');

        $exists = $this->http()->get("/collections/{$collection}");

        if ($exists->successful()) {
            return;
        }

        $response = $this->http()->put("/collections/{$collection}", [
            'vectors' => [
                'size' => config('qdrant.vector_size'),
                'distance' => 'Cosine',
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to create Qdrant collection: '.$response->body());
        }

        // Payload index on vendor_id makes the mandatory tenant filter fast
        // instead of a full scan — required at "thousands of vendors" scale.
        $this->http()->put("/collections/{$collection}/index", [
            'field_name' => 'vendor_id',
            'field_schema' => 'integer',
        ]);
    }

    public function upsert(string $pointId, array $vector, array $payload): void
    {
        if (! isset($payload['vendor_id'])) {
            throw new RuntimeException('Qdrant upsert payload must include vendor_id.');
        }

        $collection = config('qdrant.collection');

        $response = $this->http()->put("/collections/{$collection}/points", [
            'points' => [
                [
                    'id' => $pointId,
                    'vector' => $vector,
                    'payload' => $payload,
                ],
            ],
        ]);

        if ($response->failed()) {
            Log::error('Qdrant upsert failed', ['point_id' => $pointId, 'response' => $response->body()]);

            throw new RuntimeException('Qdrant upsert failed: '.$response->body());
        }
    }

    public function search(array $vector, int $vendorId, int $limit, float $scoreThreshold = 0.0): array
    {
        $collection = config('qdrant.collection');

        $response = $this->http()->post("/collections/{$collection}/points/search", [
            'vector' => $vector,
            'limit' => $limit,
            'score_threshold' => $scoreThreshold,
            'with_payload' => true,
            'filter' => [
                'must' => [
                    ['key' => 'vendor_id', 'match' => ['value' => $vendorId]],
                ],
            ],
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Qdrant search failed: '.$response->body());
        }

        return collect($response->json('result'))
            ->map(fn (array $point) => [
                'id' => (string) $point['id'],
                'score' => (float) $point['score'],
                'payload' => $point['payload'] ?? [],
            ])
            ->all();
    }

    public function delete(array $pointIds): void
    {
        if (empty($pointIds)) {
            return;
        }

        $collection = config('qdrant.collection');

        $response = $this->http()->post("/collections/{$collection}/points/delete", [
            'points' => $pointIds,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Qdrant delete failed: '.$response->body());
        }
    }
}
