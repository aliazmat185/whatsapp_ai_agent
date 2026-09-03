<?php

namespace App\Services\Ai\Contracts;

use Illuminate\Http\Client\Response;

/**
 * A chat-completion-with-tool-use call, shaped like Anthropic's Messages API
 * request/response (see AnthropicClient) — ClaudeAgentService's tool-use
 * loop reads $response->json('content') as a list of {type: text|tool_use}
 * blocks regardless of which provider actually served the request.
 */
interface LlmClient
{
    /**
     * @param  string|null  $forceToolName  When set, the model must call this
     *                                      specific tool this turn rather than
     *                                      optionally choosing to — used for
     *                                      deterministic inputs (e.g. a tapped
     *                                      WhatsApp list row) where leaving it
     *                                      to the model's judgement isn't
     *                                      reliable across providers.
     */
    public function messages(array $messages, array $tools, string $systemPrompt, ?string $forceToolName = null): Response;
}
