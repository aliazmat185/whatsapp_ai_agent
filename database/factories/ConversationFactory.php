<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'customer_phone' => '+92300'.fake()->numerify('#######'),
            'status' => 'active',
        ];
    }
}
