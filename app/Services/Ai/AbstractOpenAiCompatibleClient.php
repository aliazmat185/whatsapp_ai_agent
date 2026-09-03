<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\LlmClient;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shared plumbing for any OpenAI-compatible chat-completions provider
 * (Hugging Face Inference Providers, OpenRouter, ...) that must speak
 * Anthropic's Messages API shape to the rest of the app, since
 * ClaudeAgentService's tool-use loop threads $messages through unchanged
 * across iterations in Anthropic's block format (assistant text/tool_use
 * blocks, user tool_result blocks). Every call translates that history into
 * OpenAI's messages/tool_calls format, and translates the response back.
 *
 * Subclasses provide the endpoint/credentials/model and any provider-specific
 * request tweaks via buildPayload()/request().
 */
abstract class AbstractOpenAiCompatibleClient implements LlmClient
{
    public function messages(array $messages, array $tools, string $systemPrompt, ?string $forceToolName = null): Response
    {
        $payload = $this->basePayload($messages, $systemPrompt);

        if (! empty($tools)) {
            $payload['tools'] = $this->toOpenAiTools($tools);
            $payload['tool_choice'] = $forceToolName !== null
                ? ['type' => 'function', 'function' => ['name' => $forceToolName]]
                : 'auto';
        }

        // Free-tier serverless routers have been observed returning a
        // completely empty completion (0 completion_tokens, no text, no
        // tool_calls) on an intermittent basis, unrelated to the input — the
        // identical request succeeds on retry. A handful of quick retries
        // absorbs that flakiness before falling back to ClaudeAgentService's
        // "delay" message, which would otherwise fire far more often than a
        // real failure warrants.
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                // Free serverless tiers can take close to a minute on a cold
                // model load — give real headroom rather than the default
                // Http timeout, and catch the network-level exception below
                // so a slow request degrades to ClaudeAgentService's
                // existing "delay, please try again" branch instead of
                // crashing the job and leaving the customer with no reply.
                $response = Http::withToken($this->token())
                    ->timeout(110)
                    ->post($this->endpoint(), $payload);
            } catch (ConnectionException $e) {
                Log::error($this->providerName().' request failed', ['error' => $e->getMessage()]);

                return new Response(new PsrResponse(504, [], json_encode(['error' => $e->getMessage()])));
            }

            if (! $response->successful()) {
                return $response;
            }

            $body = $response->json();

            // Some providers return quota/credit errors as HTTP 200 with a
            // top-level "error" field rather than a 4xx/5xx — must check
            // explicitly, or this looks identical to (and gets misdiagnosed
            // as) the transient empty-completion case below.
            if (isset($body['error'])) {
                Log::error($this->providerName().' request failed', ['error' => $body['error']]);

                return new Response(new PsrResponse(402, [], json_encode(['error' => $body['error']])));
            }

            if (! $this->isEmptyCompletion($body)) {
                return $this->toAnthropicResponse($body);
            }

            Log::warning($this->providerName().' returned an empty completion, retrying', ['attempt' => $attempt]);
        }

        return $this->toAnthropicResponse($body);
    }

    abstract protected function token(): string;

    abstract protected function endpoint(): string;

    /**
     * The base request payload (model, messages, max_tokens, and any
     * provider-specific fields like a fallback model list or reasoning
     * controls) before tools/tool_choice are layered on.
     */
    abstract protected function basePayload(array $messages, string $systemPrompt): array;

    abstract protected function providerName(): string;

    private function isEmptyCompletion(array $body): bool
    {
        $message = $body['choices'][0]['message'] ?? [];

        return empty($message['content']) && empty($message['tool_calls']);
    }

    /**
     * Anthropic message content is either a plain string (initial history)
     * or an array of content blocks appended during the tool-use loop —
     * assistant turns hold text/tool_use blocks, user turns hold tool_result
     * blocks. OpenAI has no equivalent of a user message carrying multiple
     * tool results, so each tool_result block becomes its own 'tool' message.
     */
    protected function toOpenAiMessages(array $messages): array
    {
        $openAi = [];

        foreach ($messages as $message) {
            $content = $message['content'];

            if (is_string($content)) {
                $openAi[] = ['role' => $message['role'], 'content' => $content];

                continue;
            }

            if ($message['role'] === 'assistant') {
                $text = collect($content)->firstWhere('type', 'text')['text'] ?? null;
                $toolCalls = collect($content)
                    ->where('type', 'tool_use')
                    ->map(fn (array $block) => [
                        'id' => $block['id'],
                        'type' => 'function',
                        'function' => [
                            'name' => $block['name'],
                            'arguments' => json_encode($block['input'] instanceof \stdClass ? [] : $block['input']),
                        ],
                    ])
                    ->values()
                    ->all();

                $assistantMessage = ['role' => 'assistant', 'content' => $text];

                if (! empty($toolCalls)) {
                    $assistantMessage['tool_calls'] = $toolCalls;
                }

                $openAi[] = $assistantMessage;

                continue;
            }

            // user role carrying tool_result blocks
            foreach ($content as $block) {
                if ($block['type'] === 'tool_result') {
                    $openAi[] = [
                        'role' => 'tool',
                        'tool_call_id' => $block['tool_use_id'],
                        'content' => $block['content'],
                    ];
                }
            }
        }

        return $openAi;
    }

    private function toOpenAiTools(array $tools): array
    {
        return collect($tools)
            ->map(fn (array $tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['input_schema'],
                ],
            ])
            ->all();
    }

    private function toAnthropicResponse(array $body): Response
    {
        $message = $body['choices'][0]['message'] ?? [];
        $content = [];

        if (! empty($message['content'])) {
            $content[] = ['type' => 'text', 'text' => $message['content']];
        }

        foreach ($message['tool_calls'] ?? [] as $toolCall) {
            $input = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [];

            $content[] = [
                'type' => 'tool_use',
                'id' => $toolCall['id'],
                'name' => $toolCall['function']['name'],
                'input' => empty($input) ? new \stdClass() : $input,
            ];
        }

        return new Response(new PsrResponse(200, [], json_encode(['content' => $content])));
    }
}
