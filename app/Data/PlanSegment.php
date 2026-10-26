<?php

namespace App\Data;

use App\Enums\BillingCycle;
use App\Models\SubscriptionPlanPeriod;
use Carbon\CarbonImmutable;

/**
 * A stretch of time priced by one plan, as the calculator sees it. It carries the snapshotted
 * prices from subscription_plan_periods, never live plan prices.
 */
final readonly class PlanSegment
{
    public function __construct(
        public string $planName,
        public BillingCycle $billingCycle,
        public CarbonImmutable $startsOn,
        // Null means the segment is still open.
        public ?CarbonImmutable $endsOn,
        public int $basePriceMinor,
        public int $includedUnits,
        public int $overageRateMicros,
        public ?int $planPeriodId = null,
    ) {}

    public static function fromModel(SubscriptionPlanPeriod $period): self
    {
        return new self(
            planName: $period->plan->name,
            billingCycle: $period->billing_cycle,
            startsOn: $period->starts_on,
            endsOn: $period->ends_on,
            basePriceMinor: $period->base_price_minor,
            includedUnits: $period->included_units,
            overageRateMicros: $period->overage_rate_micros,
            planPeriodId: $period->id,
        );
    }

    /** The days of $period this segment covers, or null when it does not overlap at all. */
    public function coverageOf(DateRange $period): ?DateRange
    {
        $end = $this->endsOn ?? $period->end;

        if ($end->lt($this->startsOn)) {
            return null;
        }

        return (new DateRange($this->startsOn, $end))->intersect($period);
    }
}
