<?php

namespace App\Jobs\Ai;

use App\Models\Conversation;
use App\Services\Ai\Contracts\LlmClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RAG_PLAN.md long-term memory: folds everything up to the message window
 * kept by ClaudeAgentService into a compact rolling summary, so returning
 * customers get continuity without replaying full history every turn.
 * Dispatched by RunConversationAgent every N messages (see there).
 */
class SummarizeConversationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 150;

    public function __construct(
        public int $conversationId,
    ) {}

    public function handle(LlmClient $client): void
    {
        $conversation = Conversation::find($this->conversationId);

        if (! $conversation) {
            return;
        }

        $messages = $conversation->messages()->orderBy('created_at')->get();

        if ($messages->count() < 2) {
            return;
        }

        $transcript = $messages->map(fn ($m) => ($m->direction === 'inbound' ? 'Customer' : 'Assistant').': '.$this->messageToText($m)
        )->implode("\n");

        $prompt = $conversation->summary
            ? "Existing summary:\n{$conversation->summary}\n\nNew messages since then:\n{$transcript}\n\nUpdate the summary to fold in the new messages. Keep it under 150 words, factual, no commentary."
            : "Summarize this customer conversation in under 150 words, factual, no commentary — focus on what the customer wants, what's been resolved, and anything still pending:\n\n{$transcript}";

        try {
            $response = $client->messages(
                messages: [['role' => 'user', 'content' => $prompt]],
                tools: [],
                systemPrompt: 'You summarize customer service conversations concisely and factually.',
            );

            if (! $response->successful()) {
                Log::warning('Conversation summarization failed', ['conversation_id' => $conversation->id]);

                return;
            }

            $textBlock = collect($response->json('content'))->firstWhere('type', 'text');
            $summary = $textBlock['text'] ?? null;

            if ($summary) {
                $conversation->update([
                    'summary' => $summary,
                    'summarized_up_to_message_id' => $messages->last()->id,
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('Conversation summarization threw', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
        }
    }

    private function messageToText($message): string
    {
        return match ($message->message_type) {
            'text' => $message->content['text'] ?? $message->content['body'] ?? '',
            'location' => 'shared location',
            'interactive' => 'selected: '.($message->content['interactive_reply_id'] ?? ''),
            default => "[{$message->message_type} message]",
        };
    }
}
