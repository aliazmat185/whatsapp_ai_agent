<?php

return [
    // Which LlmClient implementation ClaudeAgentService's tool-use loop
    // talks to — 'anthropic', 'huggingface', 'openrouter', or 'gemini'.
    'provider' => env('AI_PROVIDER', 'anthropic'),

    // Which EmbeddingProviderContract implementation product/knowledge-base
    // search uses — 'openai' or 'gemini'. Independent of the chat provider
    // above (RAG_PLAN.md already treats embeddings as a separate AI vendor).
    'embedding_provider' => env('EMBEDDING_PROVIDER', 'openai'),

    // Cosine similarity floor ProductRetriever/knowledge-base search accepts
    // as a real match — calibrated per model, NOT a universal constant.
    // Live-tested: Gemini's gemini-embedding-001 scores relevant-but-vague
    // matches around 0.6-0.65 and clearly irrelevant queries top out near
    // 0.5, versus OpenAI's text-embedding-3-small where 0.7 was the tuned
    // floor — reusing 0.7 for Gemini would silently return zero results for
    // most non-exact queries.
    'embedding_score_threshold' => (float) env(
        'EMBEDDING_SCORE_THRESHOLD',
        env('EMBEDDING_PROVIDER', 'openai') === 'gemini' ? 0.6 : 0.7
    ),
];
