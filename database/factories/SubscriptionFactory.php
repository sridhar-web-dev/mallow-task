<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            // Always inherit the tenant from the customer so the denormalised column can't drift.
            'merchant_id' => fn (array $attributes) => Customer::find($attributes['customer_id'])->merchant_id,
            'status' => SubscriptionStatus::Active,
            'started_on' => now()->startOfMonth()->toDateString(),
            'current_period_start' => now()->startOfMonth()->toDateString(),
            'current_period_end' => now()->endOfMonth()->toDateString(),
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_on' => now()->toDateString(),
        ]);
    }
}
