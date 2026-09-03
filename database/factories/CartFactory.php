<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\Conversation;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cart>
 */
class CartFactory extends Factory
{
    public function definition(): array
    {
        $conversation = Conversation::factory()->create();
        $store = Store::factory()->create(['vendor_id' => $conversation->vendor_id]);

        return [
            'conversation_id' => $conversation->id,
            'vendor_id' => $conversation->vendor_id,
            'store_id' => $store->id,
            'status' => 'open',
        ];
    }
}
