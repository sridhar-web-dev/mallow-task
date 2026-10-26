<?php

namespace App\Actions;

use App\Enums\SubscriptionStatus;
use App\Exceptions\InvalidSubscriptionChange;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPlanPeriod;
use App\Services\DashboardCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Starts a subscription. Cycles are calendar-anchored, so a customer starting on the 16th
 * gets a first period of the 16th to month end. That short first period is what the
 * calculator prorates.
 */
class SubscribeCustomerAction
{
    public function __construct(private readonly DashboardCache $dashboards) {}

    public function execute(Customer $customer, Plan $plan, ?CarbonImmutable $startsOn = null): Subscription
    {
        $startsOn = ($startsOn ?? CarbonImmutable::today())->startOfDay();

        if ($plan->merchant_id !== $customer->merchant_id) {
            throw InvalidSubscriptionChange::planBelongsToAnotherMerchant();
        }

        if (! $plan->is_active) {
            throw InvalidSubscriptionChange::planInactive();
        }

        $subscription = DB::transaction(function () use ($customer, $plan, $startsOn) {
            // Lock the customer row so two concurrent subscribe calls cannot both pass the check.
            Customer::query()->lockForUpdate()->find($customer->id);

            if ($customer->subscriptions()->where('status', SubscriptionStatus::Active)->exists()) {
                throw InvalidSubscriptionChange::alreadySubscribed();
            }

            $cycle = $plan->billing_cycle->periodContaining($startsOn);

            $subscription = $customer->subscriptions()->create([
                'merchant_id' => $customer->merchant_id,
                'status' => SubscriptionStatus::Active,
                'started_on' => $startsOn,
                'current_period_start' => $startsOn,
                'current_period_end' => $cycle->end,
            ]);

            $subscription->planPeriods()->create(
                SubscriptionPlanPeriod::pricingFrom($plan) + ['starts_on' => $startsOn]
            );

            return $subscription;
        });

        // After commit, so a dashboard rebuilt straight away sees the new subscription.
        $this->dashboards->invalidate($customer->merchant_id);

        return $subscription;
    }
}
