<?php

namespace Tests\Feature\Billing;

use App\Jobs\AggregateDailyUsage;
use App\Models\Customer;
use App\Models\DailyUsage;
use App\Models\Merchant;
use App\Models\UsageEvent;
use App\Services\UsageAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class UsageAggregationTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-26 12:00:00'));
        $this->merchant = Merchant::factory()->create();
    }

    private function event(Customer $customer, string $date, int $quantity): void
    {
        UsageEvent::create([
            'merchant_id' => $customer->merchant_id,
            'customer_id' => $customer->id,
            'idempotency_key' => (string) Str::uuid(),
            'quantity' => $quantity,
            'usage_date' => $date,
        ]);
    }

    private function totalFor(Customer $customer, string $date): ?int
    {
        return DailyUsage::where('customer_id', $customer->id)->where('usage_date', $date)->value('total_units');
    }

    private function aggregate(string $date, ?int $chunkSize = null): int
    {
        return app(UsageAggregator::class)->aggregateDate(CarbonImmutable::parse($date), $chunkSize);
    }

    public function test_it_sums_each_customers_events_for_the_day(): void
    {
        [$alice, $bob] = Customer::factory()->for($this->merchant)->count(2)->create();

        $this->event($alice, '2026-10-26', 100);
        $this->event($alice, '2026-10-26', 250);
        $this->event($bob, '2026-10-26', 40);
        $this->event($alice, '2026-10-25', 999); // another day, must not leak in

        $this->assertSame(2, $this->aggregate('2026-10-26'));

        $this->assertSame(350, $this->totalFor($alice, '2026-10-26'));
        $this->assertSame(40, $this->totalFor($bob, '2026-10-26'));
        $this->assertNull($this->totalFor($alice, '2026-10-25'));
        $this->assertSame(2, DailyUsage::where('customer_id', $alice->id)->value('event_count'));
    }

    public function test_re_running_is_idempotent(): void
    {
        $customer = Customer::factory()->for($this->merchant)->create();
        $this->event($customer, '2026-10-26', 100);

        $this->aggregate('2026-10-26');
        $this->aggregate('2026-10-26');

        $this->assertSame(1, DailyUsage::count());
        $this->assertSame(100, $this->totalFor($customer, '2026-10-26'));
    }

    public function test_late_events_are_picked_up_by_the_next_run(): void
    {
        $customer = Customer::factory()->for($this->merchant)->create();
        $this->event($customer, '2026-10-24', 100);
        $this->aggregate('2026-10-24');

        $this->event($customer, '2026-10-24', 30); // arrives two days late
        $this->aggregate('2026-10-24');

        $this->assertSame(130, $this->totalFor($customer, '2026-10-24'));
    }

    public function test_it_pages_through_customers_in_chunks(): void
    {
        $customers = Customer::factory()->for($this->merchant)->count(5)->create();
        $customers->each(fn (Customer $c, int $i) => $this->event($c, '2026-10-26', ($i + 1) * 10));

        // A chunk size of 2 over 5 customers needs three pages (2 + 2 + 1).
        $this->assertSame(5, $this->aggregate('2026-10-26', chunkSize: 2));

        $customers->each(fn (Customer $c, int $i) => $this->assertSame(($i + 1) * 10, $this->totalFor($c, '2026-10-26')));
    }

    public function test_it_handles_an_exact_multiple_of_the_chunk_size(): void
    {
        Customer::factory()->for($this->merchant)->count(4)->create()
            ->each(fn (Customer $c) => $this->event($c, '2026-10-26', 1));

        $this->assertSame(4, $this->aggregate('2026-10-26', chunkSize: 2));
    }

    public function test_a_day_with_no_events_writes_nothing(): void
    {
        $this->assertSame(0, $this->aggregate('2026-10-26'));
        $this->assertSame(0, DailyUsage::count());
    }

    public function test_the_queued_job_aggregates_the_day(): void
    {
        $customer = Customer::factory()->for($this->merchant)->create();
        $this->event($customer, '2026-10-26', 75);

        AggregateDailyUsage::dispatch('2026-10-26');

        $this->assertSame(75, $this->totalFor($customer, '2026-10-26'));
    }

    public function test_the_command_queues_today_plus_the_late_arrival_window(): void
    {
        Queue::fake();

        $this->artisan('usage:aggregate')->assertSuccessful();

        // late_arrival_days = 3, so today plus the 3 previous days.
        Queue::assertPushed(AggregateDailyUsage::class, 4);
        foreach (['2026-10-26', '2026-10-25', '2026-10-24', '2026-10-23'] as $date) {
            Queue::assertPushed(AggregateDailyUsage::class, fn (AggregateDailyUsage $job) => $job->date === $date);
        }
    }

    public function test_the_command_accepts_a_single_date(): void
    {
        Queue::fake();

        $this->artisan('usage:aggregate', ['date' => '2026-10-01'])->assertSuccessful();

        Queue::assertPushed(AggregateDailyUsage::class, fn (AggregateDailyUsage $job) => $job->date === '2026-10-01');
        Queue::assertPushed(AggregateDailyUsage::class, 1);
    }
}
