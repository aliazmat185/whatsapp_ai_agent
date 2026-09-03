<?php

namespace App\Models;

use App\Traits\BelongsToVendor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable(['vendor_id', 'parent_id', 'name', 'slug', 'image_path', 'is_active'])]
class ProductCategory extends Model
{
    use BelongsToVendor, HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * A URL WhatsApp's servers can actually fetch. In local dev, APP_URL is
     * localhost, which WhatsApp can't reach — public_asset_base_url (an
     * ngrok tunnel) stands in for it there; production leaves it unset and
     * falls back to the normal disk URL, which is already public.
     */
    public function imageUrl(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        $base = config('whatsapp.public_asset_base_url');

        return $base
            ? rtrim($base, '/').'/storage/'.ltrim($this->image_path, '/')
            : Storage::disk('public')->url($this->image_path);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ProductCategory::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }
}
