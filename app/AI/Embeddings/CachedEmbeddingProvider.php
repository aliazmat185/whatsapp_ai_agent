<?php

namespace App\AI\Embeddings;

use App\AI\Embeddings\Contracts\EmbeddingProviderContract;
use Illuminate\Support\Facades\Cache;

/**
 * Decorates any EmbeddingProviderContract with a cache on embed() only —
 * repeat customer queries ("what are your hours") skip the OpenAI call
 * entirely (RAG_PLAN.md performance: caching). embedBatch() is intentionally
 * NOT cached: it's only ever called with fresh document/product content that
 * won't repeat, so caching it would just fill the cache store for nothing.
 */
class CachedEmbeddingProvider implements EmbeddingProviderContract
{
    private const TTL_SECONDS = 86400;

    public function __construct(
        private EmbeddingProviderContract $inner,
    ) {}

    public function embed(string $text): array
    {
        $key = 'embedding:'.$this->inner->dimensions().':'.hash('sha256', $text);

        return Cache::remember($key, self::TTL_SECONDS, fn () => $this->inner->embed($text));
    }

    public function embedBatch(array $texts): array
    {
        return $this->inner->embedBatch($texts);
    }

    public function dimensions(): int
    {
        return $this->inner->dimensions();
    }
}
