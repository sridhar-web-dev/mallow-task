<?php

namespace App\Services;

use App\Data\DateRange;
use App\Data\PlanSegment;
use App\Enums\SubscriptionStatus;
use App\Models\Merchant;
use App\Models\Subscription;
use App\Models\SubscriptionPlanPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds GET /merchants/{id}/dashboard. It reads only daily_usage (about 30 rows per customer
 * per month, with the covering (merchant_id, usage_date, customer_id, total_units) index) and
 * never touches the raw usage_events table.
 *
 * Definitions (also in the README):
 *  - "This month" is the calendar month containing the as-of date (UTC), up to and including today.
 *  - Projected overage: each active subscription's usage so far this cycle is extrapolated
 *    at its average daily rate to the end of the cycle, then priced by the same
 *    BillingCalculator that issues invoices. Mid-cycle plan changes and proration therefore
 *    match the eventual invoice exactly.
 *  - Churn risk: month-to-date usage against the same span of last month (1st to the
 *    same day). Comparing a partial month with a full one would flag everyone early in the
 *    month. A customer is flagged when usage fell by more than the threshold (50%).
 */
class MerchantDashboard
{
    public function __construct(
        private readonly BillingCalculator $calculator,
        private readonly PlanCatalog $plans,
        private readonly DashboardCache $cache,
    ) {}

    public function build(Merchant $merchant, CarbonImmutable $today): array
    {
        $today = $today->startOfDay();

        return $this->cache->remember($merchant->id, $today, function () use ($merchant, $today) {
            $subscriptions = $this->subscriptionStats($merchant->id, $today);

            return [
                'merchant_id' => $merchant->id,
                'currency' => $merchant->currency,
                'as_of' => $today->toDateString(),
                'summary' => $this->summary($merchant->id, $today, $subscriptions),
                'top_customers' => $this->topCustomers($merchant->id, $today, $subscriptions),
                'projected_overage' => [
                    'actual_to_date_minor' => array_sum(array_column($subscriptions, 'actual_overage_minor')),
                    'projected_minor' => array_sum(array_column($subscriptions, 'projected_overage_minor')),
                    'subscriptions_projected_over_allowance' => count(array_filter(array_column($subscriptions, 'projected_overage_minor'))),
                ],
                'churn_risk' => $this->churnRisk($merchant->id, $today),
                'daily_trend' => $this->dailyTrend($merchant->id, $today),
            ];
        });
    }

    /**
     * Headline cards: usage against allowance across every active subscription's current cycle,
     * the most-subscribed plan, and how fresh the rollup is.
     *
     * @param  array<int, array>  $subscriptions
     */
    private function summary(int $merchantId, CarbonImmutable $today, array $subscriptions): array
    {
        $planCounts = array_count_values(array_column($subscriptions, 'plan_id'));
        arsort($planCounts);
        $topPlan = $this->plans->find($merchantId, (int) array_key_first($planCounts));

        return [
            'active_subscriptions' => count($subscriptions),
            'cycle_used_units' => array_sum(array_column($subscriptions, 'used_units')),
            'cycle_included_units' => array_sum(array_column($subscriptions, 'included_units')),
            'most_subscribed_plan' => $topPlan === null ? null : [
                'name' => $topPlan->name,
                'billing_cycle' => $topPlan->billingCycle->value,
                'subscriptions' => $planCounts[$topPlan->id],
            ],
            // Bounded to the last week so it stays an index range scan however much history there is.
            'usage_aggregated_at' => DB::table('daily_usage')
                ->where('merchant_id', $merchantId)
                ->where('usage_date', '>=', $today->subDays(7)->toDateString())
                ->max('updated_at'),
        ];
    }

    /**
     * Merchant-wide units per day for the last 30 days, with gaps filled with zeros so the chart
     * x-axis is continuous.
     */
    private function dailyTrend(int $merchantId, CarbonImmutable $today): array
    {
        $range = new DateRange($today->subDays(29), $today);

        $totals = DB::table('daily_usage')
            ->where('merchant_id', $merchantId)
            ->whereBetween('usage_date', [$range->start->toDateString(), $range->end->toDateString()])
            ->groupBy('usage_date')
            ->selectRaw('usage_date, SUM(total_units) AS units')
            ->pluck('units', 'usage_date');

        $days = [];
        for ($day = $range->start; $day->lte($range->end); $day = $day->addDay()) {
            $days[] = ['date' => $day->toDateString(), 'units' => (int) ($totals[$day->toDateString()] ?? 0)];
        }

        return $days;
    }

    /**
     * @param  array<int, array>  $subscriptions  stats keyed by customer_id
     */
    private function topCustomers(int $merchantId, CarbonImmutable $today, array $subscriptions): array
    {
        $month = new DateRange($today->startOfMonth(), $today);

        $rows = DB::table('daily_usage')
            ->join('customers', 'customers.id', '=', 'daily_usage.customer_id')
            ->where('daily_usage.merchant_id', $merchantId)
            ->whereBetween('daily_usage.usage_date', [$month->start->toDateString(), $month->end->toDateString()])
            ->groupBy('daily_usage.customer_id', 'customers.external_ref', 'customers.name')
            ->orderByDesc('units')
            ->orderBy('daily_usage.customer_id')
            ->limit(config('billing.dashboard.top_customers'))
            ->selectRaw('daily_usage.customer_id, customers.external_ref, customers.name, SUM(daily_usage.total_units) AS units')
            ->get();

        $catalog = $this->plans->forMerchant($merchantId);

        return [
            'period' => ['start' => $month->start->toDateString(), 'end' => $month->end->toDateString()],
            'customers' => $rows->map(function ($row) use ($catalog, $subscriptions) {
                $stats = $subscriptions[$row->customer_id] ?? null;
                $included = $stats['included_units'] ?? null;

                return [
                    'customer_id' => (int) $row->customer_id,
                    'external_ref' => $row->external_ref,
                    'name' => $row->name,
                    'plan' => $catalog->get($stats['plan_id'] ?? null)?->name,
                    'units' => (int) $row->units,
                    // Against the prorated allowance of the customer's current cycle (null if not subscribed).
                    'included_units' => $included,
                    'percent_of_allowance' => $included ? round(100 * $row->units / $included, 1) : null,
                ];
            })->all(),
        ];
    }

    /**
     * One pass over the merchant's active subscriptions, pricing each one's cycle containing
     * today twice with the invoicing calculator: once on actual usage and once with usage
     * extrapolated to cycle end.
     *
     * Chunked so the database work stays flat for merchants with many subscribers: each chunk
     * loads its segments and daily usage in two queries, with no N+1. The result keeps one
     * small array per subscription.
     *
     * @return array<int, array{plan_id: int, included_units: int, used_units: int, actual_overage_minor: int, projected_overage_minor: int}> keyed by customer_id
     */
    private function subscriptionStats(int $merchantId, CarbonImmutable $today): array
    {
        $stats = [];

        Subscription::query()
            ->where('merchant_id', $merchantId)
            ->where('status', SubscriptionStatus::Active)
            ->chunkById(500, function (Collection $subscriptions) use ($today, &$stats) {
                $segments = SubscriptionPlanPeriod::query()
                    ->with('plan')
                    ->whereIn('subscription_id', $subscriptions->modelKeys())
                    ->orderBy('starts_on')
                    ->get()
                    ->groupBy('subscription_id');

                $cycles = $subscriptions->mapWithKeys(fn (Subscription $s) => [
                    $s->id => $this->cycleContaining($today, $s, $segments->get($s->id)),
                ])->filter();

                $usage = $this->dailyUsageFor($subscriptions, $cycles);

                foreach ($cycles as $subscriptionId => $cycle) {
                    $subscription = $subscriptions->find($subscriptionId);
                    $periods = $segments->get($subscriptionId);
                    $planSegments = $periods->map(fn ($p) => PlanSegment::fromModel($p))->all();
                    $actualUsage = $usage[$subscription->customer_id] ?? [];

                    $actual = $this->calculator->calculate($cycle, $planSegments, $actualUsage);
                    $projected = $this->calculator->calculate($cycle, $planSegments, $this->extrapolate($cycle, $today, $actualUsage));

                    $stats[$subscription->customer_id] = [
                        'plan_id' => $periods->firstWhere('ends_on', null)->plan_id,
                        'included_units' => $this->calculator->includedUnits($cycle, $planSegments),
                        'used_units' => array_sum(array_filter($actualUsage, fn ($date) => $cycle->contains($date), ARRAY_FILTER_USE_KEY)),
                        'actual_overage_minor' => $actual->overageMinor(),
                        'projected_overage_minor' => $projected->overageMinor(),
                    ];
                }
            });

        return $stats;
    }

    /**
     * The cycle containing today, clipped to the subscription's start date. This is not
     * current_period_*, which can still point at last month while that cycle waits out
     * the late-arrival window before invoicing.
     */
    private function cycleContaining(CarbonImmutable $today, Subscription $subscription, ?Collection $segments): ?DateRange
    {
        $open = $segments?->firstWhere('ends_on', null);

        if ($open === null) {
            return null;
        }

        $cycle = $open->billing_cycle->periodContaining($today);

        // Not started yet in this cycle, so there is nothing to project.
        if ($subscription->started_on->gt($cycle->end)) {
            return null;
        }

        return new DateRange($cycle->start->max($subscription->started_on), $cycle->end);
    }

    /**
     * @return array<int, array<string, int>> customer_id => [Y-m-d => units]
     */
    private function dailyUsageFor(Collection $subscriptions, Collection $cycles): array
    {
        if ($cycles->isEmpty()) {
            return [];
        }

        $earliest = $cycles->min(fn (DateRange $c) => $c->start)->toDateString();

        $usage = [];

        DB::table('daily_usage')
            ->whereIn('customer_id', $subscriptions->pluck('customer_id'))
            ->whereBetween('usage_date', [$earliest, $cycles->max(fn (DateRange $c) => $c->end)->toDateString()])
            ->get(['customer_id', 'usage_date', 'total_units'])
            ->each(function ($row) use (&$usage) {
                $usage[$row->customer_id][$row->usage_date] = (int) $row->total_units;
            });

        return $usage;
    }

    /**
     * Adds the projected remaining days to the actual usage. The cycle's average daily usage
     * so far (to today, inclusive) is spread across each remaining day, with the integer
     * remainder going to the earliest days, so the projected total is exactly
     * round(average × remaining days).
     *
     * @param  array<string, int>  $actual
     * @return array<string, int>
     */
    private function extrapolate(DateRange $cycle, CarbonImmutable $today, array $actual): array
    {
        if ($today->gte($cycle->end) || $today->lt($cycle->start)) {
            return $actual;
        }

        $elapsed = new DateRange($cycle->start, $today);
        $usedSoFar = array_sum(array_filter($actual, fn ($date) => $elapsed->contains($date), ARRAY_FILTER_USE_KEY));
        $remainingDays = $cycle->days() - $elapsed->days();

        $remainingUnits = intdiv(2 * $usedSoFar * $remainingDays + $elapsed->days(), 2 * $elapsed->days());
        $perDay = intdiv($remainingUnits, $remainingDays);
        $extra = $remainingUnits % $remainingDays;

        for ($i = 1; $i <= $remainingDays; $i++) {
            $date = $today->addDays($i)->toDateString();
            $actual[$date] = ($actual[$date] ?? 0) + $perDay + ($i <= $extra ? 1 : 0);
        }

        return $actual;
    }

    private function churnRisk(int $merchantId, CarbonImmutable $today): array
    {
        $current = new DateRange($today->startOfMonth(), $today);
        $previousMonth = $today->startOfMonth()->subMonth();
        // Same span of last month: 1st to the same day, clipped to last month's length (31 Mar compares with 1–28 Feb).
        $previous = new DateRange($previousMonth, $previousMonth->addDays($today->day - 1)->min($previousMonth->endOfMonth()));

        $threshold = config('billing.dashboard.churn_drop_threshold_percent');

        $rows = DB::query()
            ->fromSub(
                DB::table('daily_usage')
                    ->where('merchant_id', $merchantId)
                    ->whereBetween('usage_date', [$previous->start->toDateString(), $current->end->toDateString()])
                    ->groupBy('customer_id')
                    ->selectRaw(
                        'customer_id,
                         SUM(CASE WHEN usage_date BETWEEN ? AND ? THEN total_units ELSE 0 END) AS previous_units,
                         SUM(CASE WHEN usage_date BETWEEN ? AND ? THEN total_units ELSE 0 END) AS current_units',
                        [
                            $previous->start->toDateString(), $previous->end->toDateString(),
                            $current->start->toDateString(), $current->end->toDateString(),
                        ],
                    ),
                'mom',
            )
            ->join('customers', 'customers.id', '=', 'mom.customer_id')
            ->where('mom.previous_units', '>', 0)
            // current < previous × (100 - threshold)%, done in integers. Exactly a 50% drop is not flagged.
            ->whereRaw('mom.current_units * 100 < mom.previous_units * ?', [100 - $threshold])
            ->orderByRaw('(mom.previous_units - mom.current_units) * 1.0 / mom.previous_units DESC')
            ->orderBy('mom.customer_id')
            ->limit(config('billing.dashboard.churn_list_limit'))
            ->get(['mom.customer_id', 'customers.external_ref', 'customers.name', 'mom.previous_units', 'mom.current_units']);

        return [
            'threshold_percent' => $threshold,
            'current_period' => ['start' => $current->start->toDateString(), 'end' => $current->end->toDateString()],
            'previous_period' => ['start' => $previous->start->toDateString(), 'end' => $previous->end->toDateString()],
            'customers' => $rows->map(fn ($row) => [
                'customer_id' => (int) $row->customer_id,
                'external_ref' => $row->external_ref,
                'name' => $row->name,
                'previous_units' => (int) $row->previous_units,
                'current_units' => (int) $row->current_units,
                'drop_percent' => round(100 * ($row->previous_units - $row->current_units) / $row->previous_units, 1),
            ])->all(),
        ];
    }
}
