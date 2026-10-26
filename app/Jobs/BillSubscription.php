<?php

namespace App\Jobs;

use App\Actions\GenerateInvoiceAction;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Invoices every closed cycle of one subscription. It loops so that a subscription the
 * scheduler missed for a while (an outage, for example) catches up one invoice per cycle, in order.
 */
class BillSubscription implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public readonly int $subscriptionId) {}

    public function uniqueId(): string
    {
        return (string) $this->subscriptionId;
    }

    public function handle(GenerateInvoiceAction $generateInvoice): void
    {
        $today = CarbonImmutable::today();
        $subscription = Subscription::find($this->subscriptionId);

        while ($subscription?->isDueForBilling($today)) {
            $generateInvoice->execute($subscription);
            $subscription->refresh();
        }
    }
}
