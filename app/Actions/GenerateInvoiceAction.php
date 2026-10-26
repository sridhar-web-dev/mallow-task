<?php

namespace App\Actions;

use App\Data\DateRange;
use App\Data\InvoiceDraft;
use App\Data\InvoiceLine;
use App\Data\PlanSegment;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SubscriptionPlanPeriod;
use App\Services\BillingCalculator;
use App\Services\UsageAggregator;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Closes a subscription's current billing cycle: it prices it, stores the invoice and moves the
 * subscription on to the next cycle.
 *
 * Idempotent: if an invoice for this cycle already exists (for example, a retry after a crash
 * between insert and advance), the existing one is kept and the subscription is only advanced.
 * The unique (subscription_id, period_start) index backs this up.
 */
class GenerateInvoiceAction
{
    public function __construct(
        private readonly BillingCalculator $calculator,
        private readonly UsageAggregator $aggregator,
    ) {}

    public function execute(Subscription $subscription): Invoice
    {
        // Rebuild this customer's daily totals for the cycle from the raw events first, so the
        // invoice never depends on the hourly rollup having caught up. It is idempotent,
        // so it sits outside the transaction to keep the lock short.
        $this->aggregator->aggregateCustomer($subscription->customer_id, $subscription->billingPeriod());

        return DB::transaction(function () use ($subscription) {
            $subscription = Subscription::query()->with('merchant')->lockForUpdate()->findOrFail($subscription->id);
            $period = $subscription->billingPeriod();

            $invoice = $subscription->invoices()
                ->where('period_start', $period->start->toDateString())
                ->first()
                ?? $this->createInvoice($subscription, $this->draft($subscription, $period));

            $this->advanceToNextPeriod($subscription);

            return $invoice;
        });
    }

    private function draft(Subscription $subscription, DateRange $period): InvoiceDraft
    {
        $segments = $subscription->planPeriods()
            ->with('plan')
            ->overlapping($period)
            ->get()
            ->map(fn (SubscriptionPlanPeriod $p) => PlanSegment::fromModel($p))
            ->all();

        $usage = DB::table('daily_usage')
            ->where('customer_id', $subscription->customer_id)
            ->whereBetween('usage_date', [$period->start->toDateString(), $period->end->toDateString()])
            ->pluck('total_units', 'usage_date')
            ->map(fn ($units) => (int) $units)
            ->all();

        return $this->calculator->calculate($period, $segments, $usage);
    }

    private function createInvoice(Subscription $subscription, InvoiceDraft $draft): Invoice
    {
        $invoice = $subscription->invoices()->create([
            'merchant_id' => $subscription->merchant_id,
            'customer_id' => $subscription->customer_id,
            'period_start' => $draft->period->start,
            'period_end' => $draft->period->end,
            'currency' => $subscription->merchant->currency,
            'base_amount_minor' => $draft->baseMinor(),
            'overage_amount_minor' => $draft->overageMinor(),
            'total_minor' => $draft->totalMinor(),
            'status' => InvoiceStatus::Issued,
            'issued_at' => now(),
        ]);

        $invoice->lineItems()->createMany(array_map(fn (InvoiceLine $line) => [
            'subscription_plan_period_id' => $line->planPeriodId,
            'type' => $line->type,
            'description' => $line->description,
            'segment_start' => $line->segment->start,
            'segment_end' => $line->segment->end,
            'quantity' => $line->quantity,
            'unit_price_micros' => $line->unitPriceMicros,
            'amount_minor' => $line->amountMinor,
        ], $draft->lines));

        return $invoice;
    }

    private function advanceToNextPeriod(Subscription $subscription): void
    {
        $openSegment = $subscription->currentPlanPeriod()->first()
            ?? throw new LogicException("Subscription {$subscription->id} has no open plan segment.");

        // Periods are calendar-aligned, so the day after one ends is always the first day of the next.
        $nextPeriod = $openSegment->billing_cycle->periodContaining($subscription->current_period_end->addDay());

        $subscription->update([
            'current_period_start' => $nextPeriod->start,
            'current_period_end' => $nextPeriod->end,
        ]);
    }
}
