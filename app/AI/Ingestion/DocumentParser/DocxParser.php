<?php

namespace App\AI\Ingestion\DocumentParser;

use App\AI\Ingestion\DocumentParser\Contracts\DocumentParserContract;
use PhpOffice\PhpWord\IOFactory;

class DocxParser implements DocumentParserContract
{
    public function supports(string $mimeType): bool
    {
        return $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }

    public function extract(string $absolutePath): string
    {
        $phpWord = IOFactory::load($absolutePath, 'Word2007');
        $text = [];

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $elementText = $element->getText();
                    $text[] = is_string($elementText) ? $elementText : '';
                }
            }
        }

        return implode("\n", array_filter($text));
    }
}
