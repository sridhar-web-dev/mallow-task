<?php

namespace App\Services;

use App\Data\DateRange;
use App\Data\InvoiceDraft;
use App\Data\InvoiceLine;
use App\Data\PlanSegment;
use App\Enums\LineItemType;
use App\Support\Money;

/**
 * Pure billing maths with no database, clock or config. It takes a billing period, the plan
 * segments that cover it and the daily usage, and returns the invoice lines.
 *
 * Rules (also in the README):
 *  - Proration is by day. A segment covering D days of a cycle that is C days long
 *    (the full calendar month or year) pays base * D / C.
 *  - The included allowance is prorated the same way and rounded to the nearest unit, so a
 *    half-month subscriber gets half the allowance rather than the full one.
 *  - Each plan segment is billed on its own: usage dated inside a segment counts against
 *    that segment's allowance at that segment's overage rate. After a mid-cycle plan change,
 *    usage before the change is billed at the old plan's rate and usage after at the new one.
 *  - Usage on days covered by no segment (before the subscription started) is not billed.
 *  - Rounding is half up, once per line item, in integer minor units.
 */
class BillingCalculator
{
    /**
     * @param  list<PlanSegment>  $segments
     * @param  array<string, int>  $dailyUsage  units keyed by 'Y-m-d'
     */
    public function calculate(DateRange $period, array $segments, array $dailyUsage): InvoiceDraft
    {
        $lines = [];

        foreach ($segments as $segment) {
            $covered = $segment->coverageOf($period);

            if ($covered === null) {
                continue;
            }

            $days = $covered->days();
            $cycleDays = $segment->billingCycle->periodContaining($covered->start)->days();
            $isProrated = $days < $cycleDays;

            $baseMinor = Money::roundDiv($segment->basePriceMinor * $days, $cycleDays);

            $lines[] = new InvoiceLine(
                type: LineItemType::Base,
                description: $segment->planName.' base fee'.($isProrated ? " (prorated {$days}/{$cycleDays} days)" : ''),
                segment: $covered,
                quantity: 1,
                unitPriceMicros: $baseMinor * Money::MICROS_PER_MINOR,
                amountMinor: $baseMinor,
                planPeriodId: $segment->planPeriodId,
            );

            $allowance = $this->allowanceFor($segment, $covered);
            $used = $this->usageWithin($covered, $dailyUsage);
            $overageUnits = max(0, $used - $allowance);

            if ($overageUnits > 0) {
                $lines[] = new InvoiceLine(
                    type: LineItemType::Overage,
                    description: sprintf(
                        '%s overage: %s units over %s included (%s used)',
                        $segment->planName,
                        number_format($overageUnits),
                        number_format($allowance),
                        number_format($used),
                    ),
                    segment: $covered,
                    quantity: $overageUnits,
                    unitPriceMicros: $segment->overageRateMicros,
                    amountMinor: Money::microsToMinor($overageUnits * $segment->overageRateMicros),
                    planPeriodId: $segment->planPeriodId,
                );
            }
        }

        return new InvoiceDraft($period, $lines);
    }

    /**
     * Total prorated allowance across the segments covering $period. This is the same
     * figure each overage line is measured against, exposed for "% of allowance" displays.
     *
     * @param  list<PlanSegment>  $segments
     */
    public function includedUnits(DateRange $period, array $segments): int
    {
        $total = 0;

        foreach ($segments as $segment) {
            $covered = $segment->coverageOf($period);
            $total += $covered === null ? 0 : $this->allowanceFor($segment, $covered);
        }

        return $total;
    }

    private function allowanceFor(PlanSegment $segment, DateRange $covered): int
    {
        $cycleDays = $segment->billingCycle->periodContaining($covered->start)->days();

        return Money::roundDiv($segment->includedUnits * $covered->days(), $cycleDays);
    }

    /**
     * @param  array<string, int>  $dailyUsage
     */
    private function usageWithin(DateRange $range, array $dailyUsage): int
    {
        $total = 0;

        foreach ($dailyUsage as $date => $units) {
            if ($range->contains($date)) {
                $total += $units;
            }
        }

        return $total;
    }
}
