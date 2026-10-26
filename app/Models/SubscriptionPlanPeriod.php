<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPlanPeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'plan_id',
        'starts_on',
        'ends_on',
        'billing_cycle',
        'base_price_minor',
        'included_units',
        'overage_rate_micros',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'billing_cycle' => BillingCycle::class,
            'base_price_minor' => 'integer',
            'included_units' => 'integer',
            'overage_rate_micros' => 'integer',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
