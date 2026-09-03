<?php

namespace App\AI\Retrieval;

use App\AI\Embeddings\Contracts\EmbeddingProviderContract;
use App\AI\Retrieval\Contracts\VectorStoreContract;
use App\Models\DocumentChunk;
use App\Models\KnowledgeBaseSettings;
use App\Models\KnowledgeRetrievalLog;
use Illuminate\Support\Collection;

/**
 * RAG_PLAN.md Phase C: embeds the customer's question, searches Qdrant
 * scoped to the conversation's own vendor_id, and returns the matching
 * document_chunks. Below-threshold or empty results mean "no relevant
 * info" — the caller (search_knowledge_base tool) is responsible for the
 * graceful-refusal / escalate behavior, not this class.
 */
class KnowledgeRetriever
{
    public function __construct(
        private EmbeddingProviderContract $embeddings,
        private VectorStoreContract $vectorStore,
    ) {}

    /**
     * @return Collection<int, array{chunk: DocumentChunk, score: float}>
     */
    public function retrieve(int $vendorId, string $query, ?int $conversationId = null): Collection
    {
        $settings = KnowledgeBaseSettings::withoutGlobalScope('vendor')
            ->firstWhere('vendor_id', $vendorId);

        $limit = $settings?->max_chunks_per_query ?? 5;
        $threshold = $settings?->similarity_threshold ?? 0.75;

        $start = microtime(true);

        $vector = $this->embeddings->embed($query);
        $matches = $this->vectorStore->search($vector, $vendorId, $limit, $threshold);

        $latencyMs = (int) ((microtime(true) - $start) * 1000);

        $this->log($vendorId, $conversationId, $query, $matches, $latencyMs);

        if (empty($matches)) {
            return collect();
        }

        $chunkIds = collect($matches)->pluck('payload.chunk_id')->filter()->all();

        $chunks = DocumentChunk::withoutGlobalScope('vendor')
            ->whereIn('id', $chunkIds)
            ->get()
            ->keyBy('id');

        return collect($matches)
            ->map(function (array $match) use ($chunks) {
                $chunk = $chunks->get($match['payload']['chunk_id'] ?? null);

                return $chunk ? ['chunk' => $chunk, 'score' => $match['score']] : null;
            })
            ->filter()
            ->values();
    }

    /**
     * @param  array<int, array{id: string, score: float, payload: array<string, mixed>}>  $matches
     */
    private function log(int $vendorId, ?int $conversationId, string $query, array $matches, int $latencyMs): void
    {
        KnowledgeRetrievalLog::create([
            'vendor_id' => $vendorId,
            'conversation_id' => $conversationId,
            'kind' => 'knowledge_chunk',
            'query' => $query,
            'results_count' => count($matches),
            'top_score' => $matches[0]['score'] ?? null,
            'below_threshold' => empty($matches),
            'latency_ms' => $latencyMs,
        ]);
    }
}
