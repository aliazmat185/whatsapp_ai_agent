<?php

namespace App\Providers;

use App\AI\Embeddings\CachedEmbeddingProvider;
use App\AI\Embeddings\Contracts\EmbeddingProviderContract;
use App\AI\Embeddings\GeminiEmbeddingProvider;
use App\AI\Embeddings\OpenAiEmbeddingProvider;
use App\AI\Retrieval\Contracts\VectorStoreContract;
use App\AI\Retrieval\ProductRetriever;
use App\AI\Retrieval\QdrantVectorStore;
use App\Services\Ai\AnthropicClient;
use App\Services\Ai\Contracts\LlmClient;
use App\Services\Ai\GeminiClient;
use App\Services\Ai\HuggingFaceClient;
use App\Services\Ai\OpenRouterClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(VectorStoreContract::class, QdrantVectorStore::class);

        $this->app->bind(LlmClient::class, match (config('ai.provider')) {
            'huggingface' => HuggingFaceClient::class,
            'openrouter' => OpenRouterClient::class,
            'gemini' => GeminiClient::class,
            default => AnthropicClient::class,
        });

        $this->app->bind(EmbeddingProviderContract::class, fn ($app) => new CachedEmbeddingProvider(
            $app->make(config('ai.embedding_provider') === 'gemini' ? GeminiEmbeddingProvider::class : OpenAiEmbeddingProvider::class),
        ));

        $this->app->when(ProductRetriever::class)
            ->needs('$scoreThreshold')
            ->give(fn () => config('ai.embedding_score_threshold'));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
