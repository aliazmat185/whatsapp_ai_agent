<?php

namespace App\AI\Ingestion\DocumentParser;

use App\AI\Ingestion\DocumentParser\Contracts\DocumentParserContract;
use Smalot\PdfParser\Parser;

class PdfParser implements DocumentParserContract
{
    public function supports(string $mimeType): bool
    {
        return $mimeType === 'application/pdf';
    }

    public function extract(string $absolutePath): string
    {
        $pdf = (new Parser())->parseFile($absolutePath);

        return $pdf->getText();
    }
}
