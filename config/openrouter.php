<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenRouter (OpenAI-compatible) chat completions
    |--------------------------------------------------------------------------
    |
    | Used by OpenRouterService when streaming AI chat replies. OpenRouter
    | proxies any of: OpenAI, Anthropic, Google, Meta, Mistral, etc.
    |
    */

    'api_key' => env('OPENROUTER_API_KEY', ''),

    'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),

    'model' => env('OPENROUTER_MODEL', 'openai/gpt-4o-mini'),

    'timeout_seconds' => (int) env('OPENROUTER_TIMEOUT', 60),

    'app_name' => env('OPENROUTER_APP_NAME', env('APP_NAME', 'ChatApp')),

    'app_url' => env('OPENROUTER_APP_URL', env('APP_URL', 'http://localhost')),
];
