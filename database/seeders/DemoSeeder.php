<?php

namespace Database\Seeders;

use App\Actions\ChangeSubscriptionPlanAction;
use App\Actions\SubscribeCustomerAction;
use App\Enums\BillingCycle;
use App\Jobs\BillSubscription;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\UsageAggregator;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Realistic demo data for the walkthrough, generated relative to today so it always
 * looks current:
 *
 *  - "Acme Analytics" with 4 plans (including a yearly one) and 24 customers in
 *    different usage profiles: light, steady, heavy (over allowance), churning
 *    (usage collapsing this month) and new mid-month signups.
 *  - A downgrade last month and an upgrade this month, so invoices and the dashboard
 *    show split plan segments.
 *  - About 2.5 months of raw usage_events, rolled up into daily_usage, with every
 *    closed cycle invoiced through the real jobs.
 *  - A second merchant, for showing tenant isolation.
 *
 * History is created by moving the clock (Carbon::setTestNow) to each event's date
 * and calling the real actions, so the seeded data follows the same rules as live
 * traffic. Nothing is inserted behind the domain's back except the raw usage events
 * themselves, which are bulk-inserted for speed.
 */
class DemoSeeder extends Seeder
{
    // Fixed keys so the README's curl examples work after a fresh seed. Local demo data only.
    public const DEMO_API_KEY = 'mk_demo_acme_local_only_0000000000000000';

    public const OTHER_MERCHANT_API_KEY = 'mk_demo_other_local_only_000000000000000';

    private CarbonImmutable $today;

    private CarbonImmutable $historyStart;

    /** @var array<string, int> rough daily units per profile */
    private const PROFILES = [
        'light' => 120,
        'steady' => 450,
        'heavy' => 900,
        'churning' => 600,
        'new' => 500,
    ];

    public function run(): void
    {
        mt_srand(42); // the same "random" usage on every seed, so the recording is reproducible

        // Restore whatever clock the caller had (real time, or a test's travelTo) when done.
        $callerNow = Carbon::getTestNow();

        try {
            $this->seedAsOf(CarbonImmutable::today());
        } finally {
            Carbon::setTestNow($callerNow);
        }
    }

    private function seedAsOf(CarbonImmutable $today): void
    {
        $this->today = $today;
        $this->historyStart = $this->today->subMonthsNoOverflow(2)->startOfMonth();

        $acme = $this->merchant('Acme Analytics', 'acme', self::DEMO_API_KEY);
        $plans = $this->plans($acme);
        $customers = $this->customers($acme, $plans);

        $other = $this->merchant('Globex Metrics', 'globex', self::OTHER_MERCHANT_API_KEY);
        $globexPlan = Plan::create([
            'merchant_id' => $other->id, 'name' => 'Standard', 'billing_cycle' => BillingCycle::Monthly,
            'base_price_minor' => 99_900, 'included_units' => 10_000, 'overage_rate_micros' => 750_000,
        ]);
        foreach (['gx_001', 'gx_002', 'gx_003'] as $ref) {
            $customer = Customer::create(['merchant_id' => $other->id, 'external_ref' => $ref, 'name' => fake()->company()]);
            $this->subscribe($customer, $globexPlan, $this->historyStart);
            $customers[] = ['customer' => $customer, 'profile' => 'steady', 'from' => $this->historyStart];
        }

        $events = $this->usage($customers);
        $this->rollUp();
        $invoices = $this->invoice();

        $this->command?->info("Seeded {$events} usage events and {$invoices} invoices.");
        $this->command?->table(['Merchant', 'ID', 'API key'], [
            [$acme->name, $acme->id, self::DEMO_API_KEY],
            [$other->name, $other->id, self::OTHER_MERCHANT_API_KEY],
        ]);
    }

    private function merchant(string $name, string $slug, string $apiKey): Merchant
    {
        return Merchant::create([
            'name' => $name,
            'slug' => $slug,
            'currency' => 'INR',
            'api_key_hash' => hash('sha256', $apiKey),
        ]);
    }

    /**
     * @return array<string, Plan>
     */
    private function plans(Merchant $merchant): array
    {
        $plan = fn (string $name, BillingCycle $cycle, int $base, int $included, int $rate) => Plan::create([
            'merchant_id' => $merchant->id,
            'name' => $name,
            'billing_cycle' => $cycle,
            'base_price_minor' => $base,
            'included_units' => $included,
            'overage_rate_micros' => $rate,
        ]);

        return [
            'starter' => $plan('Starter', BillingCycle::Monthly, 49_900, 5_000, 1_000_000),      // ₹499, ₹1.00/unit
            'growth' => $plan('Growth', BillingCycle::Monthly, 149_900, 15_000, 600_000),        // ₹1,499, ₹0.60/unit
            'scale' => $plan('Scale', BillingCycle::Monthly, 499_900, 60_000, 250_000),          // ₹4,999, ₹0.25/unit
            'annual' => $plan('Annual Pro', BillingCycle::Yearly, 4_999_900, 200_000, 400_000),  // ₹49,999/yr
        ];
    }

    /**
     * @param  array<string, Plan>  $plans
     * @return list<array{customer: Customer, profile: string, from: CarbonImmutable}>
     */
    private function customers(Merchant $merchant, array $plans): array
    {
        $lastMonth = $this->today->subMonthNoOverflow()->startOfMonth();
        $thisMonth = $this->today->startOfMonth();
        // A recent signup date that is always inside the current month.
        $recent = $this->today->subDays(min(9, $this->today->day - 1));

        // [profile, plan, starts on]
        $roster = [
            ...array_fill(0, 6, ['light', 'starter', $this->historyStart]),
            ...array_fill(0, 6, ['steady', 'growth', $this->historyStart]),
            ['heavy', 'growth', $this->historyStart],
            ['heavy', 'growth', $this->historyStart],
            ['heavy', 'scale', $this->historyStart],
            ['heavy', 'starter', $this->historyStart],
            ['churning', 'growth', $this->historyStart],
            ['churning', 'starter', $this->historyStart],
            ['churning', 'growth', $this->historyStart],
            ['steady', 'starter', $lastMonth->addDays(14)],  // mid-cycle start last month: prorated invoice
            ['steady', 'annual', $this->historyStart],       // yearly plan
            ['new', 'growth', $recent],                      // signed up this month
            ['new', 'starter', $recent],
            ['steady', 'growth', $this->historyStart],       // downgraded last month (below)
        ];

        $customers = [];

        foreach ($roster as $i => [$profile, $planKey, $from]) {
            $customer = Customer::create([
                'merchant_id' => $merchant->id,
                'external_ref' => sprintf('cus_%03d', $i + 1),
                'name' => fake()->unique()->company(),
                'email' => fake()->unique()->companyEmail(),
            ]);

            $this->subscribe($customer, $plans[$planKey], $from);
            $customers[] = ['customer' => $customer, 'profile' => $profile, 'from' => $from];
        }

        // Downgrade on the 16th of last month: its invoice shows Growth and Starter segments.
        $this->changePlan($customers[23]['customer'], $plans['starter'], $lastMonth->addDays(15));

        // The heavy Starter customer upgrades to Growth part-way through this month (if the
        // month has started), so the dashboard projection spans two plan segments.
        if ($this->today->day > 1) {
            $this->changePlan($customers[15]['customer'], $plans['growth'], $thisMonth->addDays(intdiv($this->today->day, 2)));
        }

        return $customers;
    }

    private function subscribe(Customer $customer, Plan $plan, CarbonImmutable $on): void
    {
        Carbon::setTestNow($on->setTime(9, 0));
        app(SubscribeCustomerAction::class)->execute($customer, $plan);
    }

    private function changePlan(Customer $customer, Plan $plan, CarbonImmutable $on): void
    {
        Carbon::setTestNow($on->setTime(9, 0));
        app(ChangeSubscriptionPlanAction::class)->execute($customer->subscriptions()->sole(), $plan);
    }

    /**
     * Bulk-inserts raw events: each customer-day is split into 1–4 events so the rollup has
     * real work to do. Churning customers collapse to about 15% of their usual volume this month.
     *
     * @param  list<array{customer: Customer, profile: string, from: CarbonImmutable}>  $customers
     */
    private function usage(array $customers): int
    {
        $thisMonth = $this->today->startOfMonth();
        $rows = [];
        $count = 0;

        foreach ($customers as ['customer' => $customer, 'profile' => $profile, 'from' => $from]) {
            $baseline = self::PROFILES[$profile] * mt_rand(80, 120) / 100;

            foreach (CarbonPeriod::create($from, $this->today) as $day) {
                $day = CarbonImmutable::instance($day);
                $weekend = $day->isWeekend() ? 0.6 : 1.0;
                $churn = $profile === 'churning' && $day->gte($thisMonth) ? 0.15 : 1.0;
                $units = max(1, (int) round($baseline * $weekend * $churn * mt_rand(70, 130) / 100));

                $parts = mt_rand(1, 4);
                for ($p = 0; $p < $parts; $p++) {
                    $share = $p === $parts - 1 ? $units - intdiv($units, $parts) * ($parts - 1) : intdiv($units, $parts);

                    $rows[] = [
                        'merchant_id' => $customer->merchant_id,
                        'customer_id' => $customer->id,
                        'idempotency_key' => "demo-{$customer->id}-{$day->format('Ymd')}-{$p}",
                        'quantity' => max(1, $share),
                        'usage_date' => $day->toDateString(),
                        'created_at' => $day->setTime(mt_rand(0, 23), mt_rand(0, 59)),
                    ];
                }

                if (count($rows) >= 1000) {
                    $count += $this->flush($rows);
                }
            }
        }

        return $count + $this->flush($rows);
    }

    private function flush(array &$rows): int
    {
        $inserted = count($rows);
        DB::table('usage_events')->insert($rows);
        $rows = [];

        return $inserted;
    }

    private function rollUp(): void
    {
        $aggregator = app(UsageAggregator::class);

        foreach (CarbonPeriod::create($this->historyStart, $this->today) as $day) {
            $aggregator->aggregateDate(CarbonImmutable::instance($day));
        }
    }

    /**
     * Runs the real billing job for every subscription whose cycle has closed.
     */
    private function invoice(): int
    {
        Carbon::setTestNow($this->today->setTime(1, 0));

        Subscription::query()
            ->dueForBilling($this->today)
            ->pluck('id')
            ->each(fn (int $id) => BillSubscription::dispatchSync($id));

        return DB::table('invoices')->count();
    }
}
