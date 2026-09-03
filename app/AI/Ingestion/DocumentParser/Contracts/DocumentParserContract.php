<?php

namespace App\AI\Ingestion\DocumentParser\Contracts;

interface DocumentParserContract
{
    public function supports(string $mimeType): bool;

    /**
     * Extract raw text from the file at $absolutePath. No cleaning —
     * TextCleaner handles that as a separate pipeline stage.
     */
    public function extract(string $absolutePath): string;
}
