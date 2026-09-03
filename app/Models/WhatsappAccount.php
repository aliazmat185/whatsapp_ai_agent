<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'vendor_id', 'phone_number_id', 'waba_id', 'display_phone_number',
    'access_token', 'uses_platform_token', 'onboarding_status',
    'verification_method', 'rejection_reason', 'status', 'connected_at',
])]
#[Hidden(['access_token'])]
class WhatsappAccount extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'uses_platform_token' => 'boolean',
            'connected_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
