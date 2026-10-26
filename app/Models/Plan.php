<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_id',
        'name',
        'billing_cycle',
        'base_price_minor',
        'included_units',
        'overage_rate_micros',
        'is_active',
    ];

    // Matches the column default, so a freshly created model reports itself active
    // without needing a refresh() from the database.
    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'base_price_minor' => 'integer',
            'included_units' => 'integer',
            'overage_rate_micros' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
