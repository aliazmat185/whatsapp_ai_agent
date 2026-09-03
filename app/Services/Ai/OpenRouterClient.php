<?php

namespace App\Services\Ai;

/**
 * Talks to OpenRouter's chat-completions API. See
 * AbstractOpenAiCompatibleClient for the shared translation logic.
 */
class OpenRouterClient extends AbstractOpenAiCompatibleClient
{
    protected function token(): string
    {
        return config('openrouter.token');
    }

    protected function endpoint(): string
    {
        return config('openrouter.base_url').'/chat/completions';
    }

    protected function basePayload(array $messages, string $systemPrompt): array
    {
        $payload = [
            'model' => config('openrouter.model'),
            'max_tokens' => 1024,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ...$this->toOpenAiMessages($messages),
            ],
        ];

        // OpenRouter auto-fails-over to the next model in this list if the
        // primary is rate-limited/unavailable on its shared free pool —
        // free models routinely hit upstream 429s, so this is the difference
        // between an occasional retry and a customer-visible outage.
        if ($fallbacks = config('openrouter.fallback_models')) {
            $payload['models'] = [config('openrouter.model'), ...$fallbacks];
        }

        // The free reasoning-capable models tried here (e.g. the nvidia
        // Nemotron family) spend hundreds of tokens on visible chain-of-
        // thought before answering even a one-line reply — too slow for a
        // WhatsApp chat and easily exceeds max_tokens before producing any
        // customer-facing text at all. Disabling it is dramatically faster
        // and cheaper with no observed quality loss on this workload.
        $payload['reasoning'] = ['enabled' => false];

        return $payload;
    }

    protected function providerName(): string
    {
        return 'OpenRouter';
    }
}
