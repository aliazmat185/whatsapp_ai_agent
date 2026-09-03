<?php

namespace App\AI\Ingestion\DocumentParser;

use App\AI\Ingestion\DocumentParser\Contracts\DocumentParserContract;

/**
 * Handles both TXT and Markdown — Markdown is kept as raw text (headings,
 * lists etc. stay in the chunked content) rather than rendered to HTML,
 * since the LLM reads markdown syntax fine directly.
 */
class PlainTextParser implements DocumentParserContract
{
    private const SUPPORTED_MIME_TYPES = ['text/plain', 'text/markdown'];

    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, self::SUPPORTED_MIME_TYPES, true);
    }

    public function extract(string $absolutePath): string
    {
        return file_get_contents($absolutePath);
    }
}
