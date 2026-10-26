<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * Caches computed merchant dashboards, invalidated by versioned keys.
 *
 * Each merchant has a version token. The dashboard key embeds it, so invalidating
 * means writing a new token: every existing entry for that merchant becomes unreachable
 * at once and expires on its own TTL. This avoids having to enumerate keys (the as-of
 * date is part of the key) and needs no cache tags, which the database store lacks.
 *
 * The version is bumped by everything that changes a dashboard's inputs: the usage
 * rollup writing new totals, a subscription starting or changing plan, and a plan
 * being edited (for its name).
 */
class DashboardCache
{
    public function __construct(private readonly Cache $cache) {}

    public function remember(int $merchantId, CarbonImmutable $asOf, Closure $build): array
    {
        $key = sprintf('dashboard:merchant:%d:%s:%s', $merchantId, $asOf->toDateString(), $this->version($merchantId));

        return $this->cache->remember($key, config('billing.cache.dashboard_ttl'), $build);
    }

    public function invalidate(int $merchantId): void
    {
        $this->cache->forever($this->versionKey($merchantId), (string) Str::ulid());
    }

    private function version(int $merchantId): string
    {
        return $this->cache->get($this->versionKey($merchantId), 'v0');
    }

    private function versionKey(int $merchantId): string
    {
        return "dashboard:version:merchant:{$merchantId}";
    }
}
