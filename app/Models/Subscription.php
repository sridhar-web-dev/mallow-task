<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
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
            'started_on' => 'immutable_date',
            'current_period_start' => 'immutable_date',
            'current_period_end' => 'immutable_date',
            'cancelled_on' => 'immutable_date',
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
}
