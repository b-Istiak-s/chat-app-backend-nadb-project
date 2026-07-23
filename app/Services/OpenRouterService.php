<?php

namespace App\Services;

use OpenAI;
use OpenAI\Client;

/**
 * OpenRouter is OpenAI-compatible. We use openai-php/laravel client
 * pointed at https://openrouter.ai/api/v1 to stream chat completions.
 *
 * Streaming contract:
 *   - `chat($messages, $onChunk)` yields each delta chunk to the
 *     callback and returns the concatenated full response.
 *   - `generate($messages)` is a non-streaming fallback.
 */
class OpenRouterService
{
    private Client $client;

    public function __construct()
    {
        $apiKey = (string) config('openrouter.api_key');
        $baseUrl = (string) config('openrouter.base_url', 'https://openrouter.ai/api/v1');

        if ($apiKey === '') {
            throw new \RuntimeException('OpenRouter API key is not configured. Set OPENROUTER_API_KEY in .env');
        }

        $this->client = OpenAI::factory()
            ->withApiKey($apiKey)
            ->withBaseUri($baseUrl)
            ->withHttpHeader('X-Title', (string) config('openrouter.app_name', 'Chat App'))
            ->withHttpHeader('HTTP-Referer', (string) config('openrouter.app_url', 'http://localhost'))
            ->make();
    }

    public function chat(array $messages, callable $onChunk): string
    {
        $fullResponse = '';

        try {
            $stream = $this->client->chat()->createStreamed([
                'model' => (string) config('openrouter.model', 'openai/gpt-4o-mini'),
                'messages' => $messages,
            ]);

            foreach ($stream as $response) {
                $content = $response->choices[0]->delta->content ?? '';
                $fullResponse .= $content;
                if ($content !== '') {
                    $onChunk($content);
                }
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'OpenRouter request failed: '.$e->getMessage(),
                previous: $e,
            );
        }

        return $fullResponse;
    }

    public function generate(array $messages): string
    {
        try {
            $response = $this->client->chat()->create([
                'model' => (string) config('openrouter.model', 'openai/gpt-4o-mini'),
                'messages' => $messages,
            ]);

            return $response->choices[0]->message->content ?? '';
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'OpenRouter request failed: '.$e->getMessage(),
                previous: $e,
            );
        }
    }
}