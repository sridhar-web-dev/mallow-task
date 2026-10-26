<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Data\DateRange;
use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Builder;
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
            'starts_on' => DateOnly::class,
            'ends_on' => DateOnly::class,
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

    /**
     * The pricing columns copied from a plan when a segment opens. This is the snapshot that
     * keeps an in-flight cycle's bill stable if the merchant later edits the plan.
     */
    public static function pricingFrom(Plan $plan): array
    {
        return [
            'plan_id' => $plan->id,
            'billing_cycle' => $plan->billing_cycle,
            'base_price_minor' => $plan->base_price_minor,
            'included_units' => $plan->included_units,
            'overage_rate_micros' => $plan->overage_rate_micros,
        ];
    }

    public function scopeOverlapping(Builder $query, DateRange $range): void
    {
        $query->where('starts_on', '<=', $range->end->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $range->start->toDateString()));
    }
}
