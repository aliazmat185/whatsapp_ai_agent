<?php

return [
    'base_url' => env('QDRANT_URL', 'http://localhost:6333'),
    'api_key' => env('QDRANT_API_KEY'),
    'collection' => env('QDRANT_COLLECTION', 'knowledge_chunks'),
    'vector_size' => (int) env('QDRANT_VECTOR_SIZE', 1536), // matches text-embedding-3-small
];
