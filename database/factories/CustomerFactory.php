<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'external_ref' => 'cus_'.fake()->unique()->bothify('########'),
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
        ];
    }
}
