<?php

namespace App\Actions\KnowledgeBase;

use App\AI\Retrieval\Contracts\VectorStoreContract;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;

class DeleteDocumentAction
{
    public function __construct(
        private VectorStoreContract $vectorStore,
    ) {}

    public function execute(Document $document): void
    {
        $pointIds = $document->chunks()->whereNotNull('qdrant_point_id')->pluck('qdrant_point_id')->all();

        if (! empty($pointIds)) {
            $this->vectorStore->delete($pointIds);
        }

        Storage::disk('local')->delete($document->disk_path);

        $document->delete(); // cascades to document_chunks
    }
}
