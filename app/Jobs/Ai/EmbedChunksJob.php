<?php

namespace App\Jobs\Ai;

use App\AI\Embeddings\Contracts\EmbeddingProviderContract;
use App\AI\Retrieval\Contracts\VectorStoreContract;
use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * RAG_PLAN.md Phase B step 3: embed each chunk (OpenAI, batched) and upsert
 * into Qdrant with vendor_id in the payload — the only thing that makes
 * KnowledgeRetriever's per-tenant filter possible later. Batches of 100 per
 * OpenAI call, per RAG_PLAN.md embedding refresh strategy.
 */
class EmbedChunksJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    private const BATCH_SIZE = 100;

    /**
     * @param  array<int, int>  $chunkIds
     */
    public function __construct(
        public int $documentId,
        public array $chunkIds,
    ) {}

    public function handle(EmbeddingProviderContract $embeddings, VectorStoreContract $vectorStore): void
    {
        $document = Document::find($this->documentId);

        if (! $document) {
            return;
        }

        $document->update(['status' => 'embedding']);

        try {
            $vectorStore->ensureCollection();

            DocumentChunk::whereIn('id', $this->chunkIds)
                ->orderBy('chunk_index')
                ->chunk(self::BATCH_SIZE, function ($batch) use ($embeddings, $vectorStore, $document) {
                    $vectors = $embeddings->embedBatch($batch->pluck('content')->all());

                    foreach ($batch->values() as $i => $chunk) {
                        $pointId = (string) Str::uuid();

                        $vectorStore->upsert($pointId, $vectors[$i], [
                            'vendor_id' => $document->vendor_id,
                            'document_id' => $document->id,
                            'chunk_id' => $chunk->id,
                            'kind' => 'document_chunk',
                        ]);

                        $chunk->update(['qdrant_point_id' => $pointId]);
                    }
                });
        } catch (Throwable $e) {
            Log::error('Chunk embedding failed', ['document_id' => $document->id, 'error' => $e->getMessage()]);

            $document->update(['status' => 'failed', 'error_message' => 'Could not generate embeddings for this document.']);

            return;
        }

        $document->update(['status' => 'ready', 'processed_at' => now()]);
    }
}
