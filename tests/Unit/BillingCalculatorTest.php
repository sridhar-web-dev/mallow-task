<?php

namespace Tests\Unit;

use App\Data\DateRange;
use App\Data\InvoiceDraft;
use App\Data\PlanSegment;
use App\Enums\BillingCycle;
use App\Enums\LineItemType;
use App\Services\BillingCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Pure maths, with no database or framework. Amounts are paise (minor units) and rates are micros.
 *
 * Reference plans:
 *   Pro      ₹1,000 / month, 10,000 units included, ₹0.50 per extra unit
 *   Starter  ₹500 / month,    5,000 units included, ₹1.00 per extra unit
 */
class BillingCalculatorTest extends TestCase
{
    private BillingCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new BillingCalculator;
    }

    private function pro(string $from, ?string $to = null): PlanSegment
    {
        return $this->segment('Pro', $from, $to, base: 100_000, included: 10_000, rate: 500_000);
    }

    private function starter(string $from, ?string $to = null): PlanSegment
    {
        return $this->segment('Starter', $from, $to, base: 50_000, included: 5_000, rate: 1_000_000);
    }

    private function segment(
        string $name,
        string $from,
        ?string $to,
        int $base,
        int $included,
        int $rate,
        BillingCycle $cycle = BillingCycle::Monthly,
    ): PlanSegment {
        return new PlanSegment(
            planName: $name,
            billingCycle: $cycle,
            startsOn: CarbonImmutable::parse($from),
            endsOn: $to ? CarbonImmutable::parse($to) : null,
            basePriceMinor: $base,
            includedUnits: $included,
            overageRateMicros: $rate,
        );
    }

    private function bill(string $from, string $to, array $segments, array $usage = []): InvoiceDraft
    {
        return $this->calculator->calculate(new DateRange($from, $to), $segments, $usage);
    }

    // --- Full cycle, no proration -------------------------------------------------

    public function test_full_cycle_under_allowance_is_base_price_only(): void
    {
        $draft = $this->bill('2026-10-01', '2026-10-31', [$this->pro('2026-10-01')], [
            '2026-10-03' => 3_000,
            '2026-10-19' => 5_000,
        ]);

        $this->assertSame(100_000, $draft->totalMinor());
        $this->assertSame(0, $draft->overageMinor());
        $this->assertCount(1, $draft->lines);
        $this->assertSame('Pro base fee', $draft->lines[0]->description);
    }

    public function test_usage_exactly_at_allowance_has_no_overage(): void
    {
        $draft = $this->bill('2026-10-01', '2026-10-31', [$this->pro('2026-10-01')], ['2026-10-10' => 10_000]);

        $this->assertSame(0, $draft->overageMinor());
        $this->assertSame([], $draft->linesOfType(LineItemType::Overage));
    }

    public function test_one_unit_over_allowance_is_charged(): void
    {
        $draft = $this->bill('2026-10-01', '2026-10-31', [$this->pro('2026-10-01')], ['2026-10-10' => 10_001]);

        $overage = $draft->linesOfType(LineItemType::Overage)[0];
        $this->assertSame(1, $overage->quantity);
        $this->assertSame(50, $overage->amountMinor); // ₹0.50
    }

    public function test_overage_is_charged_beyond_the_allowance(): void
    {
        // 12,500 used - 10,000 included = 2,500 × ₹0.50 = ₹1,250.00
        $draft = $this->bill('2026-10-01', '2026-10-31', [$this->pro('2026-10-01')], [
            '2026-10-01' => 6_000,
            '2026-10-31' => 6_500,
        ]);

        $this->assertSame(100_000, $draft->baseMinor());
        $this->assertSame(125_000, $draft->overageMinor());
        $this->assertSame(225_000, $draft->totalMinor());
        $this->assertSame('Pro overage: 2,500 units over 10,000 included (12,500 used)', $draft->linesOfType(LineItemType::Overage)[0]->description);
    }

    public function test_zero_usage_still_bills_the_base_fee(): void
    {
        $draft = $this->bill('2026-10-01', '2026-10-31', [$this->pro('2026-10-01')]);

        $this->assertSame(100_000, $draft->totalMinor());
    }

    // --- Proration -----------------------------------------------------------------

    public function test_mid_cycle_start_prorates_base_and_allowance(): void
    {
        // Starts 16 Oct, so it covers 16 of October's 31 days.
        //   base      100,000 × 16/31 = 51,612.90 → 51,613
        //   allowance  10,000 × 16/31 =  5,161.29 →  5,161
        //   overage    6,000 - 5,161 = 839 × ₹0.50 = ₹419.50 → 41,950
        $draft = $this->bill('2026-10-16', '2026-10-31', [$this->pro('2026-10-16')], ['2026-10-20' => 6_000]);

        $this->assertSame(51_613, $draft->baseMinor());
        $this->assertSame(41_950, $draft->overageMinor());
        $this->assertSame(93_563, $draft->totalMinor());
        $this->assertSame('Pro base fee (prorated 16/31 days)', $draft->lines[0]->description);
    }

    public function test_proration_uses_the_actual_month_length_in_a_leap_february(): void
    {
        // 15–29 Feb 2028 = 15 of 29 days: 100,000 × 15/29 = 51,724.14 → 51,724
        $draft = $this->bill('2028-02-15', '2028-02-29', [$this->pro('2028-02-15')]);

        $this->assertSame(51_724, $draft->baseMinor());
        $this->assertStringContainsString('15/29', $draft->lines[0]->description);
    }

    public function test_proration_in_a_non_leap_february(): void
    {
        // 15–28 Feb 2027 = 14 of 28 days, exactly half.
        $draft = $this->bill('2027-02-15', '2027-02-28', [$this->pro('2027-02-15')]);

        $this->assertSame(50_000, $draft->baseMinor());
    }

    public function test_yearly_plan_prorates_over_days_in_the_year(): void
    {
        // 1 Jul – 31 Dec 2026 = 184 of 365 days.
        //   base      1,200,000 × 184/365 = 604,931.51 → 604,932
        //   allowance   120,000 × 184/365 =  60,493.15 →  60,493
        $yearly = $this->segment('Annual', '2026-07-01', null, base: 1_200_000, included: 120_000, rate: 500_000, cycle: BillingCycle::Yearly);

        $draft = $this->bill('2026-07-01', '2026-12-31', [$yearly], ['2026-09-01' => 60_494]);

        $this->assertSame(604_932, $draft->baseMinor());
        $this->assertSame(1, $draft->linesOfType(LineItemType::Overage)[0]->quantity);
    }

    public function test_sub_paisa_overage_rates_round_half_up_once_per_line(): void
    {
        // ₹0.0025 per unit = 2,500 micros. 2 units = 5,000 micros = 0.5 paise, which rounds to 1.
        $metered = $this->segment('Metered', '2026-10-01', null, base: 0, included: 0, rate: 2_500);

        $draft = $this->bill('2026-10-01', '2026-10-31', [$metered], ['2026-10-01' => 2]);

        $this->assertSame(1, $draft->overageMinor());
    }

    // --- Mid-cycle plan changes (requirement 8) -----------------------------------

    public function test_upgrade_mid_cycle_bills_each_segment_at_its_own_plan(): void
    {
        // Starter 1–15 Oct (15/31 days), then Pro 16–31 Oct (16/31 days).
        //   Starter base       50,000 × 15/31 = 24,193.55 → 24,194
        //   Starter allowance   5,000 × 15/31 =  2,419.35 →  2,419
        //   Starter overage     4,000 - 2,419 = 1,581 × ₹1.00 = 158,100
        //   Pro base          100,000 × 16/31 →  51,613
        //   Pro allowance      10,000 × 16/31 →   5,161
        //   Pro overage         9,000 - 5,161 = 3,839 × ₹0.50 = 191,950
        $draft = $this->bill('2026-10-01', '2026-10-31', [
            $this->starter('2026-10-01', '2026-10-15'),
            $this->pro('2026-10-16'),
        ], [
            '2026-10-05' => 4_000,
            '2026-10-20' => 9_000,
        ]);

        $this->assertSame([24_194, 158_100, 51_613, 191_950], array_map(fn ($l) => $l->amountMinor, $draft->lines));
        $this->assertSame(75_807, $draft->baseMinor());
        $this->assertSame(350_050, $draft->overageMinor());
        $this->assertSame(425_857, $draft->totalMinor());

        [$starterBase, , $proBase] = $draft->lines;
        $this->assertSame('2026-10-01 to 2026-10-15', $starterBase->segment->label());
        $this->assertSame('2026-10-16 to 2026-10-31', $proBase->segment->label());
    }

    public function test_usage_before_a_downgrade_is_billed_at_the_original_rate(): void
    {
        // Pro 1–15 Oct: allowance 10,000 × 15/31 = 4,838.71 → 4,839. 7,000 used, so 2,161 over at
        // Pro's ₹0.50 = 108,050. Starter's ₹1.00 rate must NOT apply to this usage.
        $draft = $this->bill('2026-10-01', '2026-10-31', [
            $this->pro('2026-10-01', '2026-10-15'),
            $this->starter('2026-10-16'),
        ], ['2026-10-10' => 7_000]);

        $overage = $draft->linesOfType(LineItemType::Overage);
        $this->assertCount(1, $overage);
        $this->assertSame(500_000, $overage[0]->unitPriceMicros);
        $this->assertSame(108_050, $overage[0]->amountMinor);
    }

    public function test_usage_after_an_upgrade_is_billed_at_the_new_rate(): void
    {
        $draft = $this->bill('2026-10-01', '2026-10-31', [
            $this->starter('2026-10-01', '2026-10-15'),
            $this->pro('2026-10-16'),
        ], ['2026-10-16' => 6_000]); // the change day itself belongs to the new plan

        $overage = $draft->linesOfType(LineItemType::Overage);
        $this->assertCount(1, $overage);
        $this->assertSame('Pro overage: 839 units over 5,161 included (6,000 used)', $overage[0]->description);
    }

    public function test_allowances_are_not_pooled_across_segments(): void
    {
        // Starter's unused allowance in the first half does not absorb Pro's overage in the second.
        $draft = $this->bill('2026-10-01', '2026-10-31', [
            $this->starter('2026-10-01', '2026-10-15'),
            $this->pro('2026-10-16'),
        ], ['2026-10-25' => 5_162]);

        $this->assertSame(1, $draft->linesOfType(LineItemType::Overage)[0]->quantity);
    }

    // --- Segment clipping ------------------------------------------------------------

    public function test_segments_are_clipped_to_the_billing_period(): void
    {
        $draft = $this->bill('2026-10-01', '2026-10-31', [
            $this->starter('2026-08-01', '2026-09-30'), // fully before the period
            $this->pro('2026-09-10'),                   // started last month, still open
        ]);

        $this->assertCount(1, $draft->lines);
        $this->assertSame(100_000, $draft->totalMinor());
        $this->assertSame('2026-10-01 to 2026-10-31', $draft->lines[0]->segment->label());
    }

    public function test_usage_on_days_no_segment_covers_is_not_billed(): void
    {
        $draft = $this->bill('2026-10-16', '2026-10-31', [$this->pro('2026-10-16')], [
            '2026-10-10' => 50_000, // before the subscription started
        ]);

        $this->assertSame(0, $draft->overageMinor());
    }
}
