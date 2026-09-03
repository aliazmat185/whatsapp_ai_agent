<?php

namespace App\Models;

use App\Traits\BelongsToVendor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'order_number', 'vendor_id', 'store_id', 'conversation_id', 'cart_id',
    'customer_phone', 'customer_name', 'customer_address', 'customer_lat', 'customer_lng',
    'subtotal', 'delivery_fee', 'total', 'payment_method', 'payment_status',
    'status', 'needs_attention', 'cancelled_reason',
])]
class Order extends Model
{
    use BelongsToVendor, HasFactory, SoftDeletes;

    /**
     * status => allowed next states. PLAN.md §13 — order status transitions
     * restricted to this map; no transitions out of completed/cancelled.
     */
    public const ALLOWED_TRANSITIONS = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['preparing', 'cancelled'],
        'preparing' => ['out_for_delivery', 'cancelled'],
        'out_for_delivery' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    /**
     * Statuses in which a vendor may still edit items on customer request —
     * once it's preparing/out for delivery the physical order is already in
     * motion, so items are locked.
     */
    public const EDITABLE_STATUSES = ['pending', 'confirmed'];

    protected function casts(): array
    {
        return [
            'customer_lat' => 'decimal:7',
            'customer_lng' => 'decimal:7',
            'subtotal' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'total' => 'decimal:2',
            'needs_attention' => 'boolean',
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

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true);
    }
}
