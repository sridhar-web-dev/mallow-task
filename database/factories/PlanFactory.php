<?php

namespace Database\Factories;

use App\Enums\BillingCycle;
use App\Models\Merchant;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'name' => fake()->randomElement(['Starter', 'Growth', 'Pro', 'Enterprise']),
            'billing_cycle' => BillingCycle::Monthly,
            'base_price_minor' => 100_000,      // ₹1,000.00
            'included_units' => 10_000,
            'overage_rate_micros' => 500_000,   // ₹0.50 per unit
            'is_active' => true,
        ];
    }
}
