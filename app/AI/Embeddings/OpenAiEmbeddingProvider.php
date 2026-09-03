<?php

namespace App\AI\Embeddings;

use App\AI\Embeddings\Contracts\EmbeddingProviderContract;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper over OpenAI's embeddings endpoint. Claude has no embedding
 * API, so this is a deliberate second AI vendor — used for embeddings only,
 * never for generation (see RAG_PLAN.md).
 */
class OpenAiEmbeddingProvider implements EmbeddingProviderContract
{
    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    public function embedBatch(array $texts): array
    {
        $response = Http::withToken(config('openai.api_key'))
            ->timeout(30)
            ->post(config('openai.base_url').'/embeddings', [
                'model' => config('openai.embedding_model'),
                'input' => $texts,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI embedding request failed: '.$response->body());
        }

        $data = collect($response->json('data'))->sortBy('index')->values();

        return $data->pluck('embedding')->all();
    }

    public function dimensions(): int
    {
        return (int) config('qdrant.vector_size');
    }
}
