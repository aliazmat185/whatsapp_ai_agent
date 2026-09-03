<?php

namespace App\Jobs\Ai;

use App\AI\Ingestion\Chunker;
use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * RAG_PLAN.md Phase B step 2: split the extracted text (written by
 * ParseDocumentJob to $extractedPath) into document_chunks rows, then
 * hand off to EmbedChunksJob. Chunks are persisted before embedding so a
 * failed/retried embed step never re-chunks (chunk_index stays stable).
 */
class ChunkDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $documentId,
        public string $extractedPath,
    ) {}

    public function handle(Chunker $chunker): void
    {
        $document = Document::find($this->documentId);

        if (! $document) {
            return;
        }

        $document->update(['status' => 'chunking']);

        try {
            $text = Storage::disk('local')->get($this->extractedPath);
            $chunks = $chunker->chunk($text);

            if (empty($chunks)) {
                $document->update(['status' => 'failed', 'error_message' => 'Document produced no chunks.']);

                return;
            }

            $chunkIds = DB::transaction(function () use ($document, $chunks) {
                $document->chunks()->delete(); // re-chunking on retry — drop any partial rows

                return collect($chunks)->map(fn (string $content, int $index) => DocumentChunk::create([
                    'vendor_id' => $document->vendor_id,
                    'document_id' => $document->id,
                    'chunk_index' => $index,
                    'content' => $content,
                    'token_count' => (int) (strlen($content) / 4),
                ])->id)->all();
            });
        } catch (Throwable $e) {
            Log::error('Document chunking failed', ['document_id' => $document->id, 'error' => $e->getMessage()]);

            $document->update(['status' => 'failed', 'error_message' => 'Could not chunk this document.']);

            return;
        } finally {
            Storage::disk('local')->delete($this->extractedPath);
        }

        EmbedChunksJob::dispatch($document->id, $chunkIds);
    }
}
