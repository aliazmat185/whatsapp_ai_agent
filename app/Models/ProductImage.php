<?php

namespace App\Models;

use App\Observers\ProductImageObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable(['product_id', 'path', 'is_primary', 'sort_order'])]
#[ObservedBy(ProductImageObserver::class)]
class ProductImage extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * A URL Meta's servers can actually fetch. In local dev, APP_URL is
     * localhost, which WhatsApp can't reach — public_asset_base_url (an
     * ngrok tunnel) stands in for it there; production leaves it unset and
     * falls back to the normal disk URL, which is already public.
     */
    public function publicUrl(): string
    {
        $base = config('whatsapp.public_asset_base_url');

        return $base
            ? rtrim($base, '/').'/storage/'.ltrim($this->path, '/')
            : Storage::disk('public')->url($this->path);
    }
}
