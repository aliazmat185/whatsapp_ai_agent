<?php

namespace App\AI\Prompts;

/**
 * RAG_PLAN.md context-window strategy: system prompt and grounding rules are
 * never dropped; recent conversation history is trimmed first (oldest-first)
 * when the total would exceed the budget. Token counts are estimated
 * (chars/4) — close enough for budgeting, not exact BPE.
 */
class TokenBudgetAllocator
{
    private const CHARS_PER_TOKEN = 4;

    public function __construct(
        private int $totalBudgetTokens = 8000,
    ) {}

    public function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / self::CHARS_PER_TOKEN);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages  oldest-first
     * @return array<int, array{role: string, content: string}>
     */
    public function fitMessages(array $messages, int $reservedTokens): array
    {
        $remaining = $this->totalBudgetTokens - $reservedTokens;

        if ($remaining <= 0) {
            return [];
        }

        $kept = [];
        $used = 0;

        // Walk newest-first so the most recent turns always survive the cut,
        // then reverse back to chronological order for the API call.
        foreach (array_reverse($messages) as $message) {
            $cost = $this->estimateTokens($message['content']);

            if ($used + $cost > $remaining) {
                break;
            }

            $kept[] = $message;
            $used += $cost;
        }

        return array_reverse($kept);
    }
}
