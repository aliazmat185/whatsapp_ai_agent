<?php

namespace App\AI\Embeddings;

use App\AI\Embeddings\Contracts\EmbeddingProviderContract;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper over Gemini's embedContent endpoint — an alternative to
 * OpenAiEmbeddingProvider for vendors without OpenAI credits. Gemini has no
 * batch-embed endpoint on the free tier, so embedBatch just calls embed()
 * per text; product reindexing is already one text at a time (see
 * ReindexProductJob), so this costs nothing in practice.
 */
class GeminiEmbeddingProvider implements EmbeddingProviderContract
{
    public function embed(string $text): array
    {
        $model = config('gemini.embedding_model');
        $url = config('gemini.base_url')."/models/{$model}:embedContent";

        $response = Http::timeout(30)->post($url.'?key='.config('gemini.api_key'), [
            'content' => ['parts' => [['text' => $text]]],
            'outputDimensionality' => config('gemini.embedding_dimensions'),
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Gemini embedding request failed: '.$response->body());
        }

        return $response->json('embedding.values');
    }

    public function embedBatch(array $texts): array
    {
        return array_map(fn (string $text) => $this->embed($text), $texts);
    }

    public function dimensions(): int
    {
        return (int) config('gemini.embedding_dimensions');
    }
}
