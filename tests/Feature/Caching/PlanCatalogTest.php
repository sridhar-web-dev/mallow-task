<?php

namespace Tests\Feature\Caching;

use App\Models\Merchant;
use App\Models\Plan;
use App\Services\PlanCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlanCatalogTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    private PlanCatalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();
        $this->catalog = app(PlanCatalog::class);
    }

    private function queriesDuring(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_catalogue_is_served_from_cache_after_the_first_read(): void
    {
        Plan::factory()->for($this->merchant)->count(3)->create();

        $this->assertSame(1, $this->queriesDuring(fn () => $this->catalog->forMerchant($this->merchant->id)));
        $this->assertSame(0, $this->queriesDuring(fn () => $this->catalog->forMerchant($this->merchant->id)));
        $this->assertCount(3, $this->catalog->forMerchant($this->merchant->id));
    }

    public function test_updating_a_plan_invalidates_the_cached_price(): void
    {
        $plan = Plan::factory()->for($this->merchant)->create(['base_price_minor' => 100_000]);
        $this->catalog->forMerchant($this->merchant->id);

        $plan->update(['base_price_minor' => 150_000]);

        $this->assertSame(150_000, $this->catalog->find($this->merchant->id, $plan->id)->basePriceMinor);
    }

    public function test_creating_and_deleting_plans_invalidates_the_catalogue(): void
    {
        $this->assertCount(0, $this->catalog->forMerchant($this->merchant->id));

        $plan = Plan::factory()->for($this->merchant)->create();
        $this->assertCount(1, $this->catalog->forMerchant($this->merchant->id));

        $plan->delete();
        $this->assertCount(0, $this->catalog->forMerchant($this->merchant->id));
    }

    public function test_invalidation_is_scoped_to_the_plans_merchant(): void
    {
        $other = Merchant::factory()->create();
        Plan::factory()->for($other)->create();
        $this->catalog->forMerchant($other->id);

        Plan::factory()->for($this->merchant)->create();

        $this->assertTrue(Cache::has(PlanCatalog::key($other->id)));
        $this->assertFalse(Cache::has(PlanCatalog::key($this->merchant->id)));
    }

    public function test_invalidation_waits_for_the_transaction_to_commit(): void
    {
        $plan = Plan::factory()->for($this->merchant)->create(['base_price_minor' => 100_000]);
        $this->catalog->forMerchant($this->merchant->id);

        DB::transaction(function () use ($plan) {
            $plan->update(['base_price_minor' => 150_000]);

            // Not yet committed: forgetting now would let a reader re-cache the old row.
            $this->assertTrue(Cache::has(PlanCatalog::key($this->merchant->id)));
        });

        $this->assertFalse(Cache::has(PlanCatalog::key($this->merchant->id)));
    }

    public function test_the_plans_endpoint_lists_only_active_plans_cheapest_first(): void
    {
        $key = $this->merchant->issueApiKey();
        Plan::factory()->for($this->merchant)->create(['name' => 'Pro', 'base_price_minor' => 100_000]);
        Plan::factory()->for($this->merchant)->create(['name' => 'Starter', 'base_price_minor' => 50_000]);
        Plan::factory()->for($this->merchant)->create(['name' => 'Legacy', 'is_active' => false]);
        Plan::factory()->create(['name' => 'Someone else']);

        $this->getJson('/api/plans', ['Authorization' => "Bearer {$key}"])
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Starter', 'Pro']);
    }
}
