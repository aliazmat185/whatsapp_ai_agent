<?php

return [
    'token' => env('OPENROUTER_API_KEY'),
    'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
    'model' => env('OPENROUTER_MODEL', 'nvidia/nemotron-3-super-120b-a12b:free'),

    // Auto-failover list — tried in order if the primary model above is
    // rate-limited/unavailable. See OpenRouterClient::basePayload().
    'fallback_models' => array_filter(explode(',', env('OPENROUTER_FALLBACK_MODELS', 'nvidia/nemotron-nano-9b-v2:free'))),
];
