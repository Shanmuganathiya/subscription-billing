<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => \App\Models\Merchant::factory(),
            'name' => $this->faker->word(),
            'base_price' => 999.00,
            'billing_cycle' => 'monthly',
            'included_units' => 10000,
            'overage_rate_per_unit' => 0.50,
        ];
    }
}
