<?php

namespace App\Services\Ai;

/**
 * Talks to a Hugging Face Inference Providers chat-completions model.
 * See AbstractOpenAiCompatibleClient for the shared translation logic.
 */
class HuggingFaceClient extends AbstractOpenAiCompatibleClient
{
    protected function token(): string
    {
        return config('huggingface.token');
    }

    protected function endpoint(): string
    {
        return config('huggingface.base_url').'/v1/chat/completions';
    }

    protected function basePayload(array $messages, string $systemPrompt): array
    {
        return [
            'model' => config('huggingface.model'),
            'max_tokens' => 1024,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ...$this->toOpenAiMessages($messages),
            ],
        ];
    }

    protected function providerName(): string
    {
        return 'Hugging Face';
    }
}
