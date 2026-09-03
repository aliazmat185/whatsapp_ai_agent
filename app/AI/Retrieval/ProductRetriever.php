<?php

namespace App\AI\Retrieval;

use App\AI\Embeddings\Contracts\EmbeddingProviderContract;
use App\AI\Retrieval\Contracts\VectorStoreContract;
use App\Models\KnowledgeRetrievalLog;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * RAG_PLAN.md hybrid product search: Qdrant finds semantically relevant
 * product IDs (embedded from name/description/category/tags only), then
 * this class re-fetches price/stock straight from MySQL — Claude never
 * sees stale price/stock from the vector payload (RAG_PLAN.md decision).
 */
class ProductRetriever
{
    public function __construct(
        private EmbeddingProviderContract $embeddings,
        private VectorStoreContract $vectorStore,
        private int $limit = 10,
        private float $scoreThreshold = 0.7,
    ) {}

    /**
     * @return Collection<int, array{id: int, name: string, description: ?string, price: float, in_stock: bool, quantity_available: ?int}>
     */
    public function retrieve(int $vendorId, int $storeId, string $query, ?int $conversationId = null): Collection
    {
        $start = microtime(true);

        $vector = $this->embeddings->embed($query);
        $matches = $this->vectorStore->search($vector, $vendorId, $this->limit, $this->scoreThreshold);

        $latencyMs = (int) ((microtime(true) - $start) * 1000);

        KnowledgeRetrievalLog::create([
            'vendor_id' => $vendorId,
            'conversation_id' => $conversationId,
            'kind' => 'product',
            'query' => $query,
            'results_count' => count($matches),
            'top_score' => $matches[0]['score'] ?? null,
            'below_threshold' => empty($matches),
            'latency_ms' => $latencyMs,
        ]);

        $productIds = collect($matches)
            ->filter(fn (array $m) => ($m['payload']['kind'] ?? null) === 'product')
            ->pluck('payload.product_id')
            ->filter()
            ->all();

        if (empty($productIds)) {
            return collect();
        }

        return Product::withoutGlobalScope('vendor')
            ->with(['images', 'inventories' => fn ($q) => $q->where('store_id', $storeId)])
            ->whereIn('id', $productIds)
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (Product $p) => array_search($p->id, $productIds)) // keep Qdrant relevance order
            ->values()
            ->map(function (Product $product) {
                $inventory = $product->inventories->first();

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'price' => (float) $product->base_price,
                    'in_stock' => $inventory === null || $inventory->isInStock(),
                    'quantity_available' => $inventory?->quantity,
                ];
            });
    }
}
