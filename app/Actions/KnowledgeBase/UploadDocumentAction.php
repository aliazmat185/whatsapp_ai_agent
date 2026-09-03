<?php

namespace App\Actions\KnowledgeBase;

use App\Jobs\Ai\ParseDocumentJob;
use App\Models\Document;
use App\Models\KnowledgeBase;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * RAG_PLAN.md Phase B: validated, stored, and queued — never parsed inline
 * with the upload request. source_hash dedupes re-uploads of the same file
 * within a knowledge base (unique constraint on documents table).
 */
class UploadDocumentAction
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
        'text/markdown',
    ];

    public function execute(KnowledgeBase $knowledgeBase, UploadedFile $file): Document
    {
        $mimeType = $file->getMimeType();

        if (! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException('Unsupported file type.');
        }

        $hash = hash_file('sha256', $file->getRealPath());

        $existing = Document::where('knowledge_base_id', $knowledgeBase->id)
            ->where('source_hash', $hash)
            ->first();

        if ($existing) {
            return $existing;
        }

        $diskPath = $file->store('knowledge-base/'.$knowledgeBase->vendor_id, 'local');

        $document = Document::create([
            'vendor_id' => $knowledgeBase->vendor_id,
            'knowledge_base_id' => $knowledgeBase->id,
            'original_filename' => $file->getClientOriginalName(),
            'disk_path' => $diskPath,
            'mime_type' => $mimeType,
            'size_bytes' => $file->getSize(),
            'source_hash' => $hash,
            'status' => 'pending',
        ]);

        ParseDocumentJob::dispatch($document->id);

        return $document;
    }
}
