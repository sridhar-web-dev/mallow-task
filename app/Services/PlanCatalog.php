<?php

namespace App\Services;

use App\Data\PlanData;
use App\Models\Plan;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;

/**
 * Cached plan and pricing lookups, with one cache entry per merchant holding that
 * merchant's whole catalogue.
 *
 * One entry per merchant rather than per plan: catalogues are small (a handful of
 * plans), readers usually want several plans at once (dashboard, plan listing), and
 * invalidation becomes "forget one key" with no tag support needed. That matters
 * because the database and file cache stores do not support tags.
 *
 * Invalidation is explicit, with the TTL only as a backstop:
 *  - PlanObserver forgets the merchant's entry after any plan is created, updated or
 *    deleted, and only once the transaction commits, so a reader cannot re-cache the
 *    pre-commit row.
 *  - The TTL caps the damage if a write bypasses Eloquent (raw SQL, a manual DB fix).
 *
 * Billing never reads this cache. Invoices price from the snapshot on
 * subscription_plan_periods, so a stale catalogue can never produce a wrong invoice.
 */
class PlanCatalog
{
    public function __construct(private readonly Cache $cache) {}

    /**
     * @return Collection<int, PlanData> keyed by plan id
     */
    public function forMerchant(int $merchantId): Collection
    {
        $rows = $this->cache->remember(
            self::key($merchantId),
            config('billing.cache.plan_catalog_ttl'),
            fn () => Plan::query()
                ->where('merchant_id', $merchantId)
                ->orderBy('base_price_minor')
                ->get()
                ->map(fn (Plan $plan) => PlanData::fromModel($plan)->toArray())
                ->all(),
        );

        return collect($rows)->map(fn (array $row) => PlanData::fromArray($row))->keyBy('id');
    }

    public function find(int $merchantId, int $planId): ?PlanData
    {
        return $this->forMerchant($merchantId)->get($planId);
    }

    public function forget(int $merchantId): void
    {
        $this->cache->forget(self::key($merchantId));
    }

    public static function key(int $merchantId): string
    {
        return "plan-catalog:merchant:{$merchantId}";
    }
}
