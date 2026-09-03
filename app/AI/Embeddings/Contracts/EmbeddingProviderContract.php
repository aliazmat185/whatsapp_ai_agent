<?php

namespace App\AI\Embeddings\Contracts;

interface EmbeddingProviderContract
{
    /**
     * Embed a single string, returning its vector.
     *
     * @return array<int, float>
     */
    public function embed(string $text): array;

    /**
     * Embed multiple strings in one batch call.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>> vectors in the same order as $texts
     */
    public function embedBatch(array $texts): array;

    public function dimensions(): int;
}
