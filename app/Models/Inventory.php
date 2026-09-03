<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['store_id', 'product_id', 'product_variant_id', 'quantity', 'low_stock_threshold', 'track_stock'])]
class Inventory extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'track_stock' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function isInStock(int $requestedQty = 1): bool
    {
        if (! $this->track_stock) {
            return true;
        }

        return $this->quantity >= $requestedQty;
    }

    public function isLowStock(): bool
    {
        return $this->track_stock && $this->quantity <= $this->low_stock_threshold;
    }
}
