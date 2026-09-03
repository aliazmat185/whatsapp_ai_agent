<?php

namespace App\Services\Ai;

use App\Services\Ai\Contracts\LlmClient;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to Google's Gemini generateContent API but speaks Anthropic's
 * Messages API shape to the rest of the app, matching the same contract as
 * AnthropicClient/HuggingFaceClient — see LlmClient. Gemini's wire format
 * differs enough from the OpenAI-compatible providers (contents/parts
 * instead of messages, functionCall/functionResponse instead of
 * tool_calls/tool role, and a mandatory thoughtSignature that must be
 * replayed on every multi-turn function call) that it isn't a good fit for
 * AbstractOpenAiCompatibleClient — this is a dedicated translation layer.
 */
class GeminiClient implements LlmClient
{
    public function messages(array $messages, array $tools, string $systemPrompt, ?string $forceToolName = null): Response
    {
        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => $this->toGeminiContents($messages),
        ];

        if (! empty($tools)) {
            $payload['tools'] = [['functionDeclarations' => $this->toGeminiTools($tools)]];
            $payload['toolConfig'] = $forceToolName !== null
                ? ['functionCallingConfig' => ['mode' => 'ANY', 'allowedFunctionNames' => [$forceToolName]]]
                : ['functionCallingConfig' => ['mode' => 'AUTO']];
        }

        $model = config('gemini.model');
        $url = config('gemini.base_url')."/models/{$model}:generateContent";

        try {
            $response = Http::timeout(110)->post($url.'?key='.config('gemini.api_key'), $payload);
        } catch (ConnectionException $e) {
            Log::error('Gemini request failed', ['error' => $e->getMessage()]);

            return new Response(new PsrResponse(504, [], json_encode(['error' => $e->getMessage()])));
        }

        if (! $response->successful()) {
            return $response;
        }

        return $this->toAnthropicResponse($response->json());
    }

    /**
     * Anthropic message content is either a plain string (initial history)
     * or an array of content blocks appended during the tool-use loop —
     * assistant turns hold text/tool_use blocks, user turns hold tool_result
     * blocks. Gemini has no "tool" role: function results go back as a
     * 'user'-role part carrying a functionResponse.
     */
    private function toGeminiContents(array $messages): array
    {
        // Our internal tool_result blocks only carry tool_use_id (matching
        // Anthropic's own correlation-by-id convention — the OpenAI-family
        // clients don't need the name either, they correlate by id too).
        // Gemini's functionResponse requires the function NAME specifically,
        // so build an id -> name lookup from the tool_use blocks already in
        // history before translating any tool_result block.
        $toolNamesById = [];

        foreach ($messages as $message) {
            if (is_array($message['content'])) {
                foreach ($message['content'] as $block) {
                    if (($block['type'] ?? null) === 'tool_use') {
                        $toolNamesById[$block['id']] = $block['name'];
                    }
                }
            }
        }

        $contents = [];

        foreach ($messages as $message) {
            $content = $message['content'];

            if (is_string($content)) {
                $contents[] = [
                    'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $content]],
                ];

                continue;
            }

            if ($message['role'] === 'assistant') {
                $parts = collect($content)->map(function (array $block) {
                    if ($block['type'] === 'text') {
                        return ['text' => $block['text']];
                    }

                    $part = [
                        'functionCall' => [
                            'name' => $block['name'],
                            'args' => $block['input'] instanceof \stdClass ? new \stdClass() : $block['input'],
                        ],
                    ];

                    if (! empty($block['_gemini_thought_signature'])) {
                        $part['thoughtSignature'] = $block['_gemini_thought_signature'];
                    }

                    return $part;
                })->values()->all();

                $contents[] = ['role' => 'model', 'parts' => $parts];

                continue;
            }

            // user role carrying tool_result blocks
            $parts = collect($content)
                ->where('type', 'tool_result')
                ->map(fn (array $block) => [
                    'functionResponse' => [
                        'name' => $toolNamesById[$block['tool_use_id']] ?? 'unknown',
                        'response' => ['result' => json_decode($block['content'], true)],
                    ],
                ])
                ->values()
                ->all();

            $contents[] = ['role' => 'user', 'parts' => $parts];
        }

        return $contents;
    }

    /**
     * Gemini's function schema uses UPPERCASE JSON-schema type names
     * ("OBJECT", "STRING", ...) instead of the lowercase ("object",
     * "string") our tool schemas are written in — recurse through
     * properties/items since types can appear at any nesting level.
     */
    private function toGeminiTools(array $tools): array
    {
        return collect($tools)
            ->map(fn (array $tool) => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $this->uppercaseSchemaTypes($tool['input_schema']),
            ])
            ->all();
    }

    private function uppercaseSchemaTypes(mixed $schema): mixed
    {
        if ($schema instanceof \stdClass) {
            return $schema;
        }

        if (! is_array($schema)) {
            return $schema;
        }

        foreach ($schema as $key => $value) {
            if ($key === 'type' && is_string($value)) {
                $schema[$key] = strtoupper($value);
            } elseif (is_array($value) || $value instanceof \stdClass) {
                $schema[$key] = $this->uppercaseSchemaTypes($value);
            }
        }

        return $schema;
    }

    private function toAnthropicResponse(array $body): Response
    {
        $parts = $body['candidates'][0]['content']['parts'] ?? [];
        $content = [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $content[] = ['type' => 'text', 'text' => $part['text']];

                continue;
            }

            if (isset($part['functionCall'])) {
                $args = $part['functionCall']['args'] ?? [];

                $content[] = [
                    'type' => 'tool_use',
                    'id' => $part['functionCall']['id'] ?? 'call_'.\Illuminate\Support\Str::random(12),
                    'name' => $part['functionCall']['name'],
                    'input' => empty($args) ? new \stdClass() : $args,
                    // Stashed on our own internal block, never sent to any
                    // other provider — Gemini requires this exact value
                    // replayed on the next request that includes this
                    // function call, or it rejects the request outright.
                    '_gemini_thought_signature' => $part['thoughtSignature'] ?? null,
                ];
            }
        }

        return new Response(new PsrResponse(200, [], json_encode(['content' => $content])));
    }
}
