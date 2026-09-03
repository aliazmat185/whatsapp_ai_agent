<?php

namespace App\Models;

use App\Traits\BelongsToVendor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['vendor_id', 'max_chunks_per_query', 'similarity_threshold', 'escalate_on_no_match'])]
class KnowledgeBaseSettings extends Model
{
    use BelongsToVendor, HasFactory;

    protected function casts(): array
    {
        return [
            'similarity_threshold' => 'float',
            'escalate_on_no_match' => 'boolean',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
