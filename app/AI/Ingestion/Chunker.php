<?php

namespace App\AI\Ingestion;

/**
 * Recursive-character chunking: split on paragraph breaks first, then
 * sentences, then words, only falling through to a hard character cut when a
 * single unit is still too big — keeps chunks on natural boundaries instead
 * of severing mid-sentence. ~4 chars/token estimate (RAG_PLAN.md defaults:
 * ~500 token chunks, ~50 token overlap).
 */
class Chunker
{
    private const CHARS_PER_TOKEN = 4;

    public function __construct(
        private int $chunkSizeTokens = 500,
        private int $overlapTokens = 50,
    ) {}

    /**
     * @return array<int, string>
     */
    public function chunk(string $text): array
    {
        $maxChars = $this->chunkSizeTokens * self::CHARS_PER_TOKEN;
        $overlapChars = $this->overlapTokens * self::CHARS_PER_TOKEN;

        $units = $this->splitIntoUnits($text, $maxChars);

        $chunks = [];
        $current = '';

        foreach ($units as $unit) {
            if ($current !== '' && strlen($current) + strlen($unit) > $maxChars) {
                $chunks[] = trim($current);
                $current = $this->tail($current, $overlapChars).$unit;

                continue;
            }

            $current .= $unit;
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return array_values(array_filter($chunks, fn ($c) => $c !== ''));
    }

    /**
     * Splits into paragraph/sentence/word units, each already capped at
     * $maxChars so the packing loop above never has to hard-cut mid-unit.
     *
     * @return array<int, string>
     */
    private function splitIntoUnits(string $text, int $maxChars): array
    {
        $paragraphs = preg_split('/\n{2,}/', $text) ?: [$text];
        $units = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if (strlen($paragraph) <= $maxChars) {
                $units[] = $paragraph."\n\n";

                continue;
            }

            foreach ($this->splitBySentence($paragraph, $maxChars) as $unit) {
                $units[] = $unit;
            }

            $units[] = "\n\n";
        }

        return $units;
    }

    /**
     * @return array<int, string>
     */
    private function splitBySentence(string $paragraph, int $maxChars): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph) ?: [$paragraph];
        $units = [];

        foreach ($sentences as $sentence) {
            if (strlen($sentence) <= $maxChars) {
                $units[] = $sentence.' ';

                continue;
            }

            // Sentence itself exceeds the chunk size (rare — e.g. a long
            // unbroken list) — hard-cut on word boundaries as a last resort.
            foreach (explode(' ', $sentence) as $word) {
                $units[] = $word.' ';
            }
        }

        return $units;
    }

    private function tail(string $text, int $chars): string
    {
        if ($chars <= 0 || strlen($text) <= $chars) {
            return $text;
        }

        return substr($text, -$chars);
    }
}
