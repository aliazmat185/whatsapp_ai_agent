<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'vendor_id', 'store_id', 'customer_phone', 'customer_name',
    'customer_lat', 'customer_lng', 'status', 'last_message_at',
    'summary', 'summarized_up_to_message_id',
])]
class Conversation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'customer_lat' => 'decimal:7',
            'customer_lng' => 'decimal:7',
            'last_message_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('created_at');
    }

    public function state(): HasOne
    {
        return $this->hasOne(ConversationState::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function activeCart(): ?Cart
    {
        return $this->carts()->where('status', 'open')->first();
    }

    public function hasLocation(): bool
    {
        return $this->customer_lat !== null && $this->customer_lng !== null;
    }
}
