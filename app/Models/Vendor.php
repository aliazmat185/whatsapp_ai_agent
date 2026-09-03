<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'owner_user_id', 'business_name', 'business_type', 'vendor_package_id',
    'status', 'approved_at', 'approved_by', 'rejection_reason', 'suspended_reason',
    'notification_phone', 'whatsapp_number', 'default_currency', 'timezone', 'delivery_fee',
])]
class Vendor extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'delivery_fee' => 'decimal:2',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(VendorPackage::class, 'vendor_package_id');
    }

    public function staff(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(PackageSubscription::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(VendorApproval::class);
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(ProductCategory::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function whatsappAccount(): HasOne
    {
        return $this->hasOne(WhatsappAccount::class);
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    public function activeAgent(): HasOne
    {
        return $this->hasOne(Agent::class)->where('is_active', true);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'notifiable')->latest();
    }

    public function knowledgeBases(): HasMany
    {
        return $this->hasMany(KnowledgeBase::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function knowledgeBaseSettings(): HasOne
    {
        return $this->hasOne(KnowledgeBaseSettings::class);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
