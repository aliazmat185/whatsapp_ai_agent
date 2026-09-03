<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\LlmClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Anthropic Messages API. Deliberately minimal —
 * ClaudeAgentService owns the tool-use loop, this just makes the HTTP call.
 */
class AnthropicClient implements LlmClient
{
    public function messages(array $messages, array $tools, string $systemPrompt, ?string $forceToolName = null): Response
    {
        $payload = [
            'model' => config('anthropic.model'),
            'max_tokens' => 1024,
            'system' => $systemPrompt,
            'messages' => $messages,
            'tools' => $tools,
        ];

        if ($forceToolName !== null) {
            $payload['tool_choice'] = ['type' => 'tool', 'name' => $forceToolName];
        }

        return Http::withHeaders([
            'x-api-key' => config('anthropic.api_key'),
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout(30)
            ->post(config('anthropic.base_url').'/v1/messages', $payload);
    }
}
