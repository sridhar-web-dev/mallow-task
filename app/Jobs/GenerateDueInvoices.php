<?php

namespace App\Jobs;

use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * Finds every subscription whose cycle has closed and fans out one BillSubscription job each.
 *
 * It pages with chunkById (keyset on id, served by the (status, current_period_end) index)
 * and selects only ids, so this job stays light however many subscriptions are due. The
 * heavy work runs in parallel across queue workers, and a failure only retries that one
 * subscription.
 */
class GenerateDueInvoices implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Subscription::query()
            ->dueForBilling(CarbonImmutable::today())
            ->select('id')
            ->chunkById(config('billing.invoicing.chunk_size'), function (Collection $subscriptions) {
                $subscriptions->each(fn (Subscription $s) => BillSubscription::dispatch($s->id));
            });
    }
}
