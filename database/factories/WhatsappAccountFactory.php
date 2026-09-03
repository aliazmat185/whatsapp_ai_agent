<?php

namespace Database\Factories;

use App\Models\Vendor;
use App\Models\WhatsappAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsappAccount>
 */
class WhatsappAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'phone_number_id' => fake()->numerify('##############'),
            'waba_id' => 'test-waba-id',
            'display_phone_number' => '+92300'.fake()->numerify('#######'),
            'uses_platform_token' => true,
            'onboarding_status' => 'active',
            'status' => 'active',
            'connected_at' => now(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'pending', 'onboarding_status' => 'verifying']);
    }
}
