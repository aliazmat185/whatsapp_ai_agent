<?php

return [
    'api_key' => env('ANTHROPIC_API_KEY'),
    'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
    'max_tool_iterations' => (int) env('ANTHROPIC_MAX_TOOL_ITERATIONS', 5),
    'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
];
