<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory()->approved(),
            'name' => fake()->firstName().' Assistant',
            'persona' => 'Friendly and concise.',
            'sales_goal' => 'full_order_closing',
            'language' => 'English',
            'escalation_rules' => null,
            'is_active' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }
}
