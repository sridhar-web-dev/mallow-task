<?php

namespace Tests\Feature\Billing;

use App\Actions\ChangeSubscriptionPlanAction;
use App\Actions\SubscribeCustomerAction;
use App\Enums\BillingCycle;
use App\Enums\LineItemType;
use App\Exceptions\InvalidSubscriptionChange;
use App\Jobs\BillSubscription;
use App\Jobs\GenerateDueInvoices;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end: subscribe, record raw usage, change plan, close the cycle, then check the stored invoice.
 * The tests insert raw usage_events only and never run the daily rollup, which shows
 * that invoicing re-aggregates from source and does not depend on the schedule.
 */
class InvoiceGenerationTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private Customer $customer;

    private Plan $starter;

    private Plan $pro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create(['currency' => 'INR']);
        $this->customer = Customer::factory()->for($this->merchant)->create();
        $this->starter = Plan::factory()->for($this->merchant)->create([
            'name' => 'Starter', 'base_price_minor' => 50_000, 'included_units' => 5_000, 'overage_rate_micros' => 1_000_000,
        ]);
        $this->pro = Plan::factory()->for($this->merchant)->create([
            'name' => 'Pro', 'base_price_minor' => 100_000, 'included_units' => 10_000, 'overage_rate_micros' => 500_000,
        ]);
    }

    private function on(string $date): void
    {
        $this->travelTo(CarbonImmutable::parse($date.' 09:00:00'));
    }

    private function subscribe(Plan $plan, string $on): Subscription
    {
        $this->on($on);

        return app(SubscribeCustomerAction::class)->execute($this->customer, $plan);
    }

    private function changePlan(Subscription $subscription, Plan $plan, string $on): void
    {
        $this->on($on);
        app(ChangeSubscriptionPlanAction::class)->execute($subscription, $plan);
    }

    private function usage(string $date, int $quantity): void
    {
        UsageEvent::create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $this->customer->id,
            'idempotency_key' => (string) Str::uuid(),
            'quantity' => $quantity,
            'usage_date' => $date,
        ]);
    }

    private function runBilling(string $on): void
    {
        $this->on($on);
        GenerateDueInvoices::dispatch();
    }

    public function test_a_mid_cycle_signup_is_prorated(): void
    {
        $subscription = $this->subscribe($this->pro, '2026-10-16');
        $this->usage('2026-10-18', 2_500);
        $this->usage('2026-10-27', 3_500);

        $this->runBilling('2026-11-04');

        $invoice = Invoice::sole();
        $this->assertSame('2026-10-16', $invoice->period_start->toDateString());
        $this->assertSame('2026-10-31', $invoice->period_end->toDateString());
        $this->assertSame(51_613, $invoice->base_amount_minor);
        $this->assertSame(41_950, $invoice->overage_amount_minor);
        $this->assertSame(93_563, $invoice->total_minor);
        $this->assertSame('INR', $invoice->currency);

        $subscription->refresh();
        $this->assertSame('2026-11-01', $subscription->current_period_start->toDateString());
        $this->assertSame('2026-11-30', $subscription->current_period_end->toDateString());
    }

    public function test_a_mid_cycle_upgrade_bills_each_segment_at_its_own_rate(): void
    {
        $subscription = $this->subscribe($this->starter, '2026-10-01');
        $this->usage('2026-10-05', 4_000);

        $this->changePlan($subscription, $this->pro, '2026-10-16');
        $this->usage('2026-10-20', 9_000);

        $this->runBilling('2026-11-04');

        $invoice = Invoice::sole();
        $this->assertSame(425_857, $invoice->total_minor);

        $lines = $invoice->lineItems()->orderBy('id')->get();
        $this->assertSame(
            [['base', '2026-10-01', 24_194], ['overage', '2026-10-01', 158_100], ['base', '2026-10-16', 51_613], ['overage', '2026-10-16', 191_950]],
            $lines->map(fn ($l) => [$l->type->value, $l->segment_start->toDateString(), $l->amount_minor])->all(),
        );
        $this->assertSame(1_000_000, $lines[1]->unit_price_micros);
        $this->assertSame(500_000, $lines[3]->unit_price_micros);
    }

    public function test_usage_before_a_downgrade_keeps_the_original_rate(): void
    {
        $subscription = $this->subscribe($this->pro, '2026-10-01');
        $this->usage('2026-10-10', 7_000);
        $this->changePlan($subscription, $this->starter, '2026-10-16');

        $this->runBilling('2026-11-04');

        $overage = Invoice::sole()->lineItems()->where('type', LineItemType::Overage)->sole();
        $this->assertSame(500_000, $overage->unit_price_micros);
        $this->assertSame(108_050, $overage->amount_minor);
    }

    public function test_the_invoice_waits_for_the_late_arrival_window_to_close(): void
    {
        $this->subscribe($this->pro, '2026-10-01');

        // Usage dated 31 Oct may arrive until 3 Nov, so 3 Nov is still too early to invoice.
        $this->runBilling('2026-11-03');
        $this->assertSame(0, Invoice::count());

        $this->usage('2026-10-31', 12_000); // a late arrival, still counted

        $this->runBilling('2026-11-04');
        $this->assertSame(100_000, Invoice::sole()->overage_amount_minor);
    }

    public function test_re_running_billing_never_double_invoices(): void
    {
        $this->subscribe($this->pro, '2026-10-01');

        $this->runBilling('2026-11-04');
        $this->runBilling('2026-11-04');
        BillSubscription::dispatch(Subscription::sole()->id);

        $this->assertSame(1, Invoice::count());
    }

    public function test_missed_cycles_are_caught_up_in_order(): void
    {
        $subscription = $this->subscribe($this->pro, '2026-08-01');

        $this->runBilling('2026-11-04');

        $this->assertSame(
            ['2026-08-01', '2026-09-01', '2026-10-01'],
            Invoice::orderBy('period_start')->get()->map(fn ($i) => $i->period_start->toDateString())->all(),
        );
        $this->assertSame('2026-11-01', $subscription->fresh()->current_period_start->toDateString());
    }

    public function test_editing_a_plans_price_does_not_change_an_in_flight_cycle(): void
    {
        $this->subscribe($this->pro, '2026-10-01');

        $this->pro->update(['base_price_minor' => 999_999]);

        $this->runBilling('2026-11-04');
        $this->assertSame(100_000, Invoice::sole()->base_amount_minor);
    }

    // --- Plan change rules -----------------------------------------------------------

    public function test_a_change_on_the_segment_start_day_reprices_in_place(): void
    {
        $subscription = $this->subscribe($this->starter, '2026-10-16');
        $this->changePlan($subscription, $this->pro, '2026-10-16');

        $segment = $subscription->planPeriods()->sole();
        $this->assertSame($this->pro->id, $segment->plan_id);
        $this->assertSame(100_000, $segment->base_price_minor);
    }

    public function test_a_plan_change_closes_the_old_segment_the_day_before(): void
    {
        $subscription = $this->subscribe($this->starter, '2026-10-01');
        $this->changePlan($subscription, $this->pro, '2026-10-16');

        [$old, $new] = $subscription->planPeriods()->get();
        $this->assertSame('2026-10-15', $old->ends_on->toDateString());
        $this->assertSame('2026-10-16', $new->starts_on->toDateString());
        $this->assertNull($new->ends_on);
    }

    public function test_it_rejects_a_plan_from_another_merchant(): void
    {
        $subscription = $this->subscribe($this->starter, '2026-10-01');
        $foreign = Plan::factory()->create();

        $this->expectException(InvalidSubscriptionChange::class);
        $this->changePlan($subscription, $foreign, '2026-10-16');
    }

    public function test_it_rejects_a_change_of_billing_cycle(): void
    {
        $subscription = $this->subscribe($this->starter, '2026-10-01');
        $yearly = Plan::factory()->for($this->merchant)->create(['billing_cycle' => BillingCycle::Yearly]);

        $this->expectException(InvalidSubscriptionChange::class);
        $this->changePlan($subscription, $yearly, '2026-10-16');
    }

    public function test_it_rejects_a_backdated_change(): void
    {
        $subscription = $this->subscribe($this->starter, '2026-10-01');
        $this->on('2026-10-20');

        $this->expectException(InvalidSubscriptionChange::class);
        app(ChangeSubscriptionPlanAction::class)->execute($subscription, $this->pro, CarbonImmutable::parse('2026-10-10'));
    }

    public function test_it_rejects_changing_to_the_current_plan(): void
    {
        $subscription = $this->subscribe($this->pro, '2026-10-01');

        $this->expectException(InvalidSubscriptionChange::class);
        $this->changePlan($subscription, $this->pro, '2026-10-16');
    }

    public function test_a_plan_created_without_is_active_can_be_subscribed_to(): void
    {
        // Regression: relying on the DB default alone left is_active null on the in-memory model.
        $plan = $this->merchant->plans()->create([
            'name' => 'Basic', 'billing_cycle' => BillingCycle::Monthly,
            'base_price_minor' => 10_000, 'included_units' => 100, 'overage_rate_micros' => 1_000_000,
        ]);

        $this->assertInstanceOf(Subscription::class, $this->subscribe($plan, '2026-10-01'));
    }

    public function test_a_customer_cannot_hold_two_active_subscriptions(): void
    {
        $this->subscribe($this->pro, '2026-10-01');

        $this->expectException(InvalidSubscriptionChange::class);
        $this->subscribe($this->starter, '2026-10-05');
    }
}
