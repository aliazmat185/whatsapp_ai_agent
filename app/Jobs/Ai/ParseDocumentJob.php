<?php

namespace App\Jobs\Ai;

use App\AI\Ingestion\DocumentParser\DocumentParserResolver;
use App\AI\Ingestion\TextCleaner;
use App\Models\Document;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * RAG_PLAN.md Phase B step 1: extract raw text from an uploaded document,
 * clean it, and hand off to ChunkDocumentJob. Runs on the queue so upload
 * requests never block on PDF/DOCX parsing.
 */
class ParseDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $documentId,
    ) {}

    public function handle(DocumentParserResolver $resolver, TextCleaner $cleaner): void
    {
        $document = Document::find($this->documentId);

        if (! $document) {
            return;
        }

        $document->update(['status' => 'parsing']);

        try {
            $absolutePath = Storage::disk('local')->path($document->disk_path);
            $parser = $resolver->resolve($document->mime_type);
            $text = $cleaner->clean($parser->extract($absolutePath));

            if (trim($text) === '') {
                $document->update([
                    'status' => 'failed',
                    'error_message' => 'No extractable text found in document.',
                ]);

                return;
            }

            // Parsed text can be large (multi-MB PDFs) — stash on the private
            // disk rather than passing it through the queue payload, which
            // Redis/DB queues serialize as one JSON blob.
            $extractedPath = "knowledge-base-extracted/{$document->id}.txt";
            Storage::disk('local')->put($extractedPath, $text);
        } catch (Throwable $e) {
            Log::error('Document parsing failed', ['document_id' => $document->id, 'error' => $e->getMessage()]);

            $document->update(['status' => 'failed', 'error_message' => 'Could not parse this document.']);

            return;
        }

        ChunkDocumentJob::dispatch($document->id, $extractedPath);
    }
}
