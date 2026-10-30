<?php

namespace Tests\Feature;

use App\Enums\LineItemType;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\Merchant;
use App\Models\SubscriptionPlanPeriod;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Keeps the demo used in the screen recording working. The seeder builds its history
 * relative to "today", so it is exercised on awkward days too: a month's 1st, February, and year end.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public static function seedDates(): array
    {
        return [
            'mid month' => ['2026-10-26'],
            'first of month' => ['2026-11-01'],
            'leap February' => ['2028-02-29'],
            'year end' => ['2026-12-31'],
        ];
    }

    #[DataProvider('seedDates')]
    public function test_the_demo_seed_produces_a_working_dashboard_and_invoices(string $today): void
    {
        $this->travelTo(CarbonImmutable::parse($today.' 10:00:00'));

        $this->seed(DemoSeeder::class);

        $acme = Merchant::where('slug', 'acme')->sole();

        // At least one closed month is invoiced for each of the 20 Acme customers on a monthly
        // plan since the start of the history (on the 1st, only the oldest month has closed).
        $this->assertGreaterThanOrEqual(20, Invoice::where('merchant_id', $acme->id)->count());
        $this->assertGreaterThan(0, InvoiceLineItem::where('type', LineItemType::Overage)->count());
        // The downgrade last month left a closed segment behind.
        $this->assertGreaterThan(0, SubscriptionPlanPeriod::whereNotNull('ends_on')->count());

        $response = $this->getJson("/api/merchants/{$acme->id}/dashboard", [
            'Authorization' => 'Bearer '.DemoSeeder::DEMO_API_KEY,
        ])->assertOk();

        $this->assertCount(5, $response->json('data.top_customers.customers'));
        // Regression: the rollup used to be stamped with the last back-dated action's clock.
        $this->assertStringStartsWith($today, $response->json('data.summary.usage_aggregated_at'));
        $this->assertGreaterThan(0, $response->json('data.projected_overage.projected_minor'));

        // The other demo merchant's key cannot read Acme's dashboard.
        $this->getJson("/api/merchants/{$acme->id}/dashboard", [
            'Authorization' => 'Bearer '.DemoSeeder::OTHER_MERCHANT_API_KEY,
        ])->assertForbidden();
    }

    public function test_churning_customers_show_up_once_the_month_is_under_way(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-26 10:00:00'));

        $this->seed(DemoSeeder::class);

        $acme = Merchant::where('slug', 'acme')->sole();

        $churn = $this->getJson("/api/merchants/{$acme->id}/dashboard", [
            'Authorization' => 'Bearer '.DemoSeeder::DEMO_API_KEY,
        ])->json('data.churn_risk.customers');

        $this->assertEqualsCanonicalizing(['cus_017', 'cus_018', 'cus_019'], array_column($churn, 'external_ref'));
    }
}
