<?php

namespace App\Jobs\Ai;

use App\AI\Embeddings\Contracts\EmbeddingProviderContract;
use App\AI\Retrieval\Contracts\VectorStoreContract;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * RAG_PLAN.md hybrid product search: embeds searchable content only
 * (name/description/category/tags). Dispatched by ProductObserver when that
 * content changes — never for price/stock updates, which stay MySQL-only
 * and are re-fetched fresh at query time by ProductRetriever.
 */
class ReindexProductJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $productId,
    ) {}

    public function handle(EmbeddingProviderContract $embeddings, VectorStoreContract $vectorStore): void
    {
        $product = Product::withoutGlobalScope('vendor')->with('category')->find($this->productId);

        if (! $product) {
            return;
        }

        $content = $this->searchableContent($product);
        $hash = hash('sha256', $content);

        if ($product->content_hash === $hash) {
            return; // no searchable-field change since last index
        }

        try {
            $vectorStore->ensureCollection();

            $vector = $embeddings->embed($content);
            $pointId = $product->qdrant_point_id ?? (string) Str::uuid();

            $vectorStore->upsert($pointId, $vector, [
                'vendor_id' => $product->vendor_id,
                'product_id' => $product->id,
                'kind' => 'product',
            ]);

            $product->update(['content_hash' => $hash, 'qdrant_point_id' => $pointId]);
        } catch (Throwable $e) {
            Log::error('Product reindex failed', ['product_id' => $product->id, 'error' => $e->getMessage()]);
        }
    }

    private function searchableContent(Product $product): string
    {
        return implode("\n", array_filter([
            $product->name,
            $product->description,
            $product->category?->name,
            $product->ai_search_keywords,
        ]));
    }
}
