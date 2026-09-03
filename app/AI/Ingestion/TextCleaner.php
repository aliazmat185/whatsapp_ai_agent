<?php

namespace App\AI\Ingestion;

class TextCleaner
{
    public function clean(string $text): string
    {
        // Strip null bytes and non-printable control chars (parsers occasionally
        // emit these from malformed PDFs) — keep tab/newline.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);

        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Collapse runs of blank lines and trailing spaces without touching
        // intentional paragraph breaks (chunker relies on double-newlines).
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
