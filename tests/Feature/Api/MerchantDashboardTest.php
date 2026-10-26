<?php

namespace Tests\Feature\Api;

use App\Actions\ChangeSubscriptionPlanAction;
use App\Actions\SubscribeCustomerAction;
use App\Models\Customer;
use App\Models\DailyUsage;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\UsageEvent;
use App\Services\UsageAggregator;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "Today" is 20 Oct 2026 unless stated otherwise. The dashboard reads only daily_usage, so most
 * tests seed that table directly.
 */
class MerchantDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private string $apiKey;

    private Plan $starter;

    private Plan $pro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();
        $this->apiKey = $this->merchant->issueApiKey();
        $this->starter = Plan::factory()->for($this->merchant)->create([
            'name' => 'Starter', 'base_price_minor' => 50_000, 'included_units' => 5_000, 'overage_rate_micros' => 1_000_000,
        ]);
        $this->pro = Plan::factory()->for($this->merchant)->create([
            'name' => 'Pro', 'base_price_minor' => 100_000, 'included_units' => 10_000, 'overage_rate_micros' => 500_000,
        ]);

        $this->on('2026-10-20');
    }

    private function on(string $date): void
    {
        $this->travelTo(CarbonImmutable::parse($date.' 09:00:00'));
    }

    private function customer(string $ref, ?Merchant $merchant = null): Customer
    {
        return Customer::factory()->for($merchant ?? $this->merchant)->create(['external_ref' => $ref, 'name' => Str::title($ref)]);
    }

    private function subscribe(Customer $customer, Plan $plan, string $on): void
    {
        $today = CarbonImmutable::now();
        $this->on($on);
        app(SubscribeCustomerAction::class)->execute($customer, $plan);
        $this->travelTo($today);
    }

    private function daily(Customer $customer, string $from, string $to, int $perDay): void
    {
        foreach (CarbonPeriod::create($from, $to) as $day) {
            DailyUsage::create([
                'merchant_id' => $customer->merchant_id,
                'customer_id' => $customer->id,
                'usage_date' => $day->toDateString(),
                'total_units' => $perDay,
                'event_count' => 1,
            ]);
        }
    }

    private function dashboard(?int $merchantId = null, ?string $apiKey = null): TestResponse
    {
        return $this->getJson(
            '/api/merchants/'.($merchantId ?? $this->merchant->id).'/dashboard',
            ['Authorization' => 'Bearer '.($apiKey ?? $this->apiKey)],
        );
    }

    // --- Top customers -----------------------------------------------------------------

    public function test_top_five_customers_by_usage_this_month(): void
    {
        $customers = collect(['cus_a' => 100, 'cus_b' => 600, 'cus_c' => 300, 'cus_d' => 500, 'cus_e' => 200, 'cus_f' => 400])
            ->map(function (int $units, string $ref) {
                $customer = $this->customer($ref);
                $this->daily($customer, '2026-10-01', '2026-10-02', $units / 2);

                return $customer;
            });

        $this->subscribe($customers['cus_b'], $this->pro, '2026-10-01');

        // Huge usage last month must not count, and neither must another merchant's customers.
        $this->daily($customers['cus_a'], '2026-09-01', '2026-09-30', 10_000);
        $this->daily($this->customer('cus_foreign', Merchant::factory()->create()), '2026-10-01', '2026-10-01', 99_999);

        $this->dashboard()
            ->assertOk()
            ->assertJsonPath('data.top_customers.period', ['start' => '2026-10-01', 'end' => '2026-10-20'])
            ->assertJsonPath('data.top_customers.customers.*.external_ref', ['cus_b', 'cus_d', 'cus_f', 'cus_c', 'cus_e'])
            ->assertJsonPath('data.top_customers.customers.*.units', [600, 500, 400, 300, 200])
            ->assertJsonPath('data.top_customers.customers.0.plan', 'Pro')
            ->assertJsonPath('data.top_customers.customers.1.plan', null);
    }

    // --- Projected overage -------------------------------------------------------------

    public function test_projected_overage_extrapolates_each_subscription_to_cycle_end(): void
    {
        // Pro since 1 Oct, 400/day for 20 days = 8,000 so far. Projection over the remaining 11 days:
        // 8,000 × 11/20 = 4,400, giving 12,400 in total → 2,400 over × ₹0.50 = 120,000.
        $pro = $this->customer('cus_pro');
        $this->subscribe($pro, $this->pro, '2026-10-01');
        $this->daily($pro, '2026-10-01', '2026-10-20', 400);

        // Starter since 11 Oct (21-day cycle), 300/day for 10 days = 3,000 → 3,300 more = 6,300.
        // Prorated allowance 5,000 × 21/31 = 3,387, so 2,913 over × ₹1.00 = 291,300.
        $starter = $this->customer('cus_starter');
        $this->subscribe($starter, $this->starter, '2026-10-11');
        $this->daily($starter, '2026-10-11', '2026-10-20', 300);

        $this->dashboard()
            ->assertOk()
            ->assertJsonPath('data.projected_overage', [
                'actual_to_date_minor' => 0,
                'projected_minor' => 411_300,
                'subscriptions_projected_over_allowance' => 2,
            ]);
    }

    public function test_actual_overage_to_date_is_reported_alongside_the_projection(): void
    {
        // 15,000 used by 20 Oct, already 5,000 over → 250,000. Projected: 15,000 × 11/20 = 8,250
        // more, so 23,250 → 13,250 over × ₹0.50 = 662,500.
        $customer = $this->customer('cus_heavy');
        $this->subscribe($customer, $this->pro, '2026-10-01');
        $this->daily($customer, '2026-10-02', '2026-10-02', 15_000);

        $this->dashboard()
            ->assertJsonPath('data.projected_overage.actual_to_date_minor', 250_000)
            ->assertJsonPath('data.projected_overage.projected_minor', 662_500);
    }

    public function test_projection_prices_each_plan_segment_at_its_own_rate(): void
    {
        // Starter 1–15 Oct: 6,000 used against a 2,419 allowance → 3,581 × ₹1.00 = 358,100 (already incurred).
        // Pro 16–31 Oct: 2,000 actual + 4,400 projected = 6,400 against 5,161 → 1,239 × ₹0.50 = 61,950.
        $customer = $this->customer('cus_upgrader');
        $this->subscribe($customer, $this->starter, '2026-10-01');
        $this->on('2026-10-16');
        app(ChangeSubscriptionPlanAction::class)->execute($customer->subscriptions()->sole(), $this->pro);
        $this->on('2026-10-20');

        $this->daily($customer, '2026-10-01', '2026-10-20', 400);

        $this->dashboard()
            ->assertJsonPath('data.projected_overage.actual_to_date_minor', 358_100)
            ->assertJsonPath('data.projected_overage.projected_minor', 420_050);
    }

    public function test_cancelled_subscriptions_are_not_projected(): void
    {
        $customer = $this->customer('cus_gone');
        $this->subscribe($customer, $this->pro, '2026-10-01');
        $customer->subscriptions()->update(['status' => 'cancelled']);
        $this->daily($customer, '2026-10-01', '2026-10-20', 5_000);

        $this->dashboard()->assertJsonPath('data.projected_overage.projected_minor', 0);
    }

    // --- Churn risk --------------------------------------------------------------------

    public function test_churn_risk_flags_drops_of_more_than_half_month_over_month(): void
    {
        // Compared spans: 1–20 Oct against 1–20 Sep.
        $cases = [
            'cus_drop60' => [1_000, 400],   // flagged (60%)
            'cus_drop40' => [1_000, 600],   // not flagged
            'cus_drop50' => [1_000, 500],   // exactly 50%: not "more than half"
            'cus_stopped' => [500, null],   // flagged (100%)
            'cus_new' => [null, 100],       // no baseline, so not flagged
        ];

        foreach ($cases as $ref => [$september, $october]) {
            $customer = $this->customer($ref);
            $september && $this->daily($customer, '2026-09-05', '2026-09-05', $september);
            $october && $this->daily($customer, '2026-10-05', '2026-10-05', $october);
        }

        // Late-September usage falls outside the comparable span, so it is no baseline.
        $late = $this->customer('cus_late_sept');
        $this->daily($late, '2026-09-25', '2026-09-25', 5_000);
        $this->daily($late, '2026-10-05', '2026-10-05', 100);

        $this->dashboard()
            ->assertJsonPath('data.churn_risk.previous_period', ['start' => '2026-09-01', 'end' => '2026-09-20'])
            ->assertJsonPath('data.churn_risk.current_period', ['start' => '2026-10-01', 'end' => '2026-10-20'])
            ->assertJsonPath('data.churn_risk.customers.*.external_ref', ['cus_stopped', 'cus_drop60'])
            ->assertJsonPath('data.churn_risk.customers.1.previous_units', 1_000)
            ->assertJsonPath('data.churn_risk.customers.1.current_units', 400)
            ->assertJsonPath('data.churn_risk.customers.1.drop_percent', 60);
    }

    public function test_churn_comparison_is_clipped_to_a_shorter_previous_month(): void
    {
        $merchant = $this->merchant;
        $this->on('2027-03-31');

        $this->dashboard($merchant->id)
            ->assertJsonPath('data.churn_risk.previous_period', ['start' => '2027-02-01', 'end' => '2027-02-28']);
    }

    // --- Access and caching ------------------------------------------------------------

    public function test_a_merchant_cannot_read_another_merchants_dashboard(): void
    {
        $other = Merchant::factory()->create();

        $this->dashboard($other->id)->assertForbidden();
        $this->getJson("/api/merchants/{$this->merchant->id}/dashboard")->assertUnauthorized();
        // Same answer for an id that doesn't exist, so merchant ids can't be enumerated.
        $this->dashboard(999_999)->assertForbidden();
    }

    public function test_the_dashboard_is_cached_until_the_usage_rollup_changes(): void
    {
        $customer = $this->customer('cus_a');
        $this->daily($customer, '2026-10-01', '2026-10-01', 100);
        $this->dashboard()->assertJsonPath('data.top_customers.customers.0.units', 100);

        // A write that bypasses the aggregator is not seen: the result is served from cache.
        DailyUsage::where('customer_id', $customer->id)->update(['total_units' => 999]);
        $this->dashboard()->assertJsonPath('data.top_customers.customers.0.units', 100);

        // The aggregator bumps the merchant's dashboard version, so the next read recomputes.
        UsageEvent::create([
            'merchant_id' => $this->merchant->id, 'customer_id' => $customer->id,
            'idempotency_key' => 'evt-cache-1', 'quantity' => 250, 'usage_date' => '2026-10-01',
        ]);
        app(UsageAggregator::class)->aggregateDate(CarbonImmutable::parse('2026-10-01'));

        $this->dashboard()->assertJsonPath('data.top_customers.customers.0.units', 250);
    }

    public function test_renaming_a_plan_refreshes_the_dashboard(): void
    {
        $customer = $this->customer('cus_a');
        $this->subscribe($customer, $this->pro, '2026-10-01');
        $this->daily($customer, '2026-10-01', '2026-10-01', 100);
        $this->dashboard()->assertJsonPath('data.top_customers.customers.0.plan', 'Pro');

        $this->pro->update(['name' => 'Pro 2026']);

        $this->dashboard()->assertJsonPath('data.top_customers.customers.0.plan', 'Pro 2026');
    }
}
