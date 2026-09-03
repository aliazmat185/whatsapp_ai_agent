<?php

namespace App\Models;

use App\Traits\BelongsToVendor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'vendor_id', 'knowledge_base_id', 'original_filename', 'disk_path',
    'mime_type', 'size_bytes', 'source_hash', 'status', 'error_message', 'processed_at',
])]
class Document extends Model
{
    use BelongsToVendor, HasFactory;

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }
}
