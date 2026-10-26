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

        return $this->cache->remember($merchant->id, $today, fn () => [
            'merchant_id' => $merchant->id,
            'currency' => $merchant->currency,
            'as_of' => $today->toDateString(),
            'top_customers' => $this->topCustomers($merchant->id, $today),
            'projected_overage' => $this->projectedOverage($merchant->id, $today),
            'churn_risk' => $this->churnRisk($merchant->id, $today),
        ]);
    }

    private function topCustomers(int $merchantId, CarbonImmutable $today): array
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

        $planIds = $this->currentPlanIds($rows->pluck('customer_id'));
        $catalog = $this->plans->forMerchant($merchantId);

        return [
            'period' => ['start' => $month->start->toDateString(), 'end' => $month->end->toDateString()],
            'customers' => $rows->map(fn ($row) => [
                'customer_id' => (int) $row->customer_id,
                'external_ref' => $row->external_ref,
                'name' => $row->name,
                'plan' => $catalog->get($planIds[$row->customer_id] ?? null)?->name,
                'units' => (int) $row->units,
            ])->all(),
        ];
    }

    /**
     * @return array<int, int> customer_id => plan_id of their open plan segment
     */
    private function currentPlanIds(Collection $customerIds): array
    {
        if ($customerIds->isEmpty()) {
            return [];
        }

        return DB::table('subscription_plan_periods')
            ->join('subscriptions', 'subscriptions.id', '=', 'subscription_plan_periods.subscription_id')
            ->whereIn('subscriptions.customer_id', $customerIds)
            ->where('subscriptions.status', SubscriptionStatus::Active->value)
            ->whereNull('subscription_plan_periods.ends_on')
            ->pluck('subscription_plan_periods.plan_id', 'subscriptions.customer_id')
            ->all();
    }

    private function projectedOverage(int $merchantId, CarbonImmutable $today): array
    {
        $actualMinor = 0;
        $projectedMinor = 0;
        $subscriptionsOver = 0;

        // Chunked so memory stays flat for merchants with many subscribers. Each chunk loads its
        // segments and daily usage in two queries, with no N+1.
        Subscription::query()
            ->where('merchant_id', $merchantId)
            ->where('status', SubscriptionStatus::Active)
            ->chunkById(500, function (Collection $subscriptions) use ($today, &$actualMinor, &$projectedMinor, &$subscriptionsOver) {
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
                    $planSegments = $segments->get($subscriptionId)->map(fn ($p) => PlanSegment::fromModel($p))->all();
                    $actualUsage = $usage[$subscription->customer_id] ?? [];

                    $actual = $this->calculator->calculate($cycle, $planSegments, $actualUsage);
                    $projected = $this->calculator->calculate($cycle, $planSegments, $this->extrapolate($cycle, $today, $actualUsage));

                    $actualMinor += $actual->overageMinor();
                    $projectedMinor += $projected->overageMinor();
                    $subscriptionsOver += $projected->overageMinor() > 0 ? 1 : 0;
                }
            });

        return [
            'actual_to_date_minor' => $actualMinor,
            'projected_minor' => $projectedMinor,
            'subscriptions_projected_over_allowance' => $subscriptionsOver,
        ];
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
