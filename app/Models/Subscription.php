<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Data\DateRange;
use App\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'merchant_id',
        'status',
        'started_on',
        'current_period_start',
        'current_period_end',
        'cancelled_on',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'started_on' => DateOnly::class,
            'current_period_start' => DateOnly::class,
            'current_period_end' => DateOnly::class,
            'cancelled_on' => DateOnly::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function planPeriods(): HasMany
    {
        return $this->hasMany(SubscriptionPlanPeriod::class)->orderBy('starts_on');
    }

    public function currentPlanPeriod(): HasOne
    {
        return $this->hasOne(SubscriptionPlanPeriod::class)->whereNull('ends_on');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function billingPeriod(): DateRange
    {
        return new DateRange($this->current_period_start, $this->current_period_end);
    }

    /**
     * A cycle is invoiced only once the late-arrival window after it has closed. Usage
     * dated on the last day of the cycle can arrive for that many days, so invoicing
     * earlier would under-bill.
     */
    public static function billableCutoff(CarbonImmutable $today): CarbonImmutable
    {
        return $today->startOfDay()->subDays(config('billing.usage.late_arrival_days'));
    }

    public function scopeDueForBilling(Builder $query, CarbonImmutable $today): void
    {
        $query->where('status', SubscriptionStatus::Active)
            ->where('current_period_end', '<', self::billableCutoff($today)->toDateString());
    }

    public function isDueForBilling(CarbonImmutable $today): bool
    {
        return $this->status === SubscriptionStatus::Active
            && $this->current_period_end->lt(self::billableCutoff($today));
    }
}
