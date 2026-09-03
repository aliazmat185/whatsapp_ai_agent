<?php

return [
    'api_key' => env('GEMINI_API_KEY'),
    'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
    'model' => env('GEMINI_MODEL', 'gemini-3.1-flash-lite'),
    'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001'),
    'embedding_dimensions' => (int) env('GEMINI_EMBEDDING_DIMENSIONS', 3072),
];
