<?php

namespace App\Actions;

use App\Enums\SubscriptionStatus;
use App\Exceptions\InvalidSubscriptionChange;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPlanPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Upgrades or downgrades a subscription mid-cycle (requirement 8).
 *
 * The open plan segment is closed the day before $effectiveOn and a new segment opens on
 * $effectiveOn with the new plan's pricing snapshotted. Nothing is billed here: at cycle end
 * the calculator prices each segment for the days it covered, at its own rates.
 *
 * Usage is metered per day, so changes take effect at the start of a day and usage dated on
 * the change day belongs to the new plan.
 */
class ChangeSubscriptionPlanAction
{
    public function execute(Subscription $subscription, Plan $newPlan, ?CarbonImmutable $effectiveOn = null): SubscriptionPlanPeriod
    {
        $today = CarbonImmutable::today();
        $effectiveOn = ($effectiveOn ?? $today)->startOfDay();

        return DB::transaction(function () use ($subscription, $newPlan, $effectiveOn, $today) {
            // The same row lock is taken by invoice generation, so a plan change can never
            // interleave with the cycle being billed.
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);
            $current = $subscription->currentPlanPeriod()->firstOrFail();

            $this->guard($subscription, $current, $newPlan, $effectiveOn, $today);

            // Effective on the day the current segment started (for example, a change on the
            // signup day): no time has passed on the old plan, so the segment is repriced in place
            // rather than leaving a zero-length segment behind.
            if ($effectiveOn->equalTo($current->starts_on)) {
                $current->update(SubscriptionPlanPeriod::pricingFrom($newPlan));

                return $current;
            }

            $current->update(['ends_on' => $effectiveOn->subDay()]);

            return $subscription->planPeriods()->create(
                SubscriptionPlanPeriod::pricingFrom($newPlan) + ['starts_on' => $effectiveOn]
            );
        });
    }

    private function guard(
        Subscription $subscription,
        SubscriptionPlanPeriod $current,
        Plan $newPlan,
        CarbonImmutable $effectiveOn,
        CarbonImmutable $today,
    ): void {
        if ($subscription->status !== SubscriptionStatus::Active) {
            throw InvalidSubscriptionChange::notActive();
        }

        if ($newPlan->merchant_id !== $subscription->merchant_id) {
            throw InvalidSubscriptionChange::planBelongsToAnotherMerchant();
        }

        if (! $newPlan->is_active) {
            throw InvalidSubscriptionChange::planInactive();
        }

        if ($newPlan->id === $current->plan_id) {
            throw InvalidSubscriptionChange::samePlan();
        }

        // Cross-cycle changes (monthly to yearly) would also need the cycle boundaries moved.
        // That is out of scope; see README.
        if ($newPlan->billing_cycle !== $current->billing_cycle) {
            throw InvalidSubscriptionChange::billingCycleMismatch();
        }

        // Backdating would re-price usage that has already been accepted at the old rate.
        if ($effectiveOn->lt($today)) {
            throw InvalidSubscriptionChange::effectiveDateInPast();
        }

        if ($effectiveOn->lt($current->starts_on)) {
            throw InvalidSubscriptionChange::effectiveBeforeCurrentSegment();
        }
    }
}
