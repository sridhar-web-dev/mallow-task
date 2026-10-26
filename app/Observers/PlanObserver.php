<?php

namespace App\Observers;

use App\Models\Plan;
use App\Services\DashboardCache;
use App\Services\PlanCatalog;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Runs after commit: forgetting the cache inside an open transaction would let a
 * concurrent reader re-cache the old row before the write becomes visible.
 */
class PlanObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly PlanCatalog $catalog,
        private readonly DashboardCache $dashboards,
    ) {}

    public function saved(Plan $plan): void
    {
        $this->invalidate($plan);
    }

    public function deleted(Plan $plan): void
    {
        $this->invalidate($plan);
    }

    private function invalidate(Plan $plan): void
    {
        $this->catalog->forget($plan->merchant_id);
        // The dashboard shows plan names next to its top customers.
        $this->dashboards->invalidate($plan->merchant_id);
    }
}
