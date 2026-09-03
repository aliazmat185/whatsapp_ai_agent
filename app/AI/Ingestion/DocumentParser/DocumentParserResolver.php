<?php

namespace App\AI\Ingestion\DocumentParser;

use App\AI\Ingestion\DocumentParser\Contracts\DocumentParserContract;
use RuntimeException;

class DocumentParserResolver
{
    /** @var array<int, DocumentParserContract> */
    private array $parsers;

    public function __construct(
        PdfParser $pdfParser,
        DocxParser $docxParser,
        PlainTextParser $plainTextParser,
    ) {
        $this->parsers = [$pdfParser, $docxParser, $plainTextParser];
    }

    public function resolve(string $mimeType): DocumentParserContract
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($mimeType)) {
                return $parser;
            }
        }

        throw new RuntimeException("No parser available for mime type: {$mimeType}");
    }
}
