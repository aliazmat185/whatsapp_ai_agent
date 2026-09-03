<?php

return [
    'token' => env('HF_TOKEN'),
    'model' => env('HF_MODEL', 'Qwen/Qwen2.5-72B-Instruct'),
    'base_url' => env('HF_BASE_URL', 'https://router.huggingface.co'),
];
