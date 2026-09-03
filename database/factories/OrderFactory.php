<?php

namespace Database\Factories;

use App\Models\Cart;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        // Build vendor/store/conversation/cart as one consistent chain so
        // overriding vendor_id alone (a common test pattern) doesn't leave
        // store_id/cart_id pointing at an unrelated vendor's records.
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $cart = Cart::factory()->create([
            'conversation_id' => $conversation->id,
            'vendor_id' => $vendor->id,
            'store_id' => $store->id,
        ]);

        return [
            'order_number' => 'ORD-'.now()->format('Ymd').'-'.Str::padLeft((string) fake()->unique()->numberBetween(1, 9999), 4, '0'),
            'vendor_id' => $vendor->id,
            'store_id' => $store->id,
            'conversation_id' => $conversation->id,
            'cart_id' => $cart->id,
            'customer_phone' => '+92300'.fake()->numerify('#######'),
            'subtotal' => 1000,
            'delivery_fee' => 0,
            'total' => 1000,
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'status' => 'pending',
        ];
    }

    /**
     * Use when the test needs the order's vendor/store/conversation/cart to
     * all consistently belong to a specific vendor (e.g. testing vendor
     * scoping) — overriding vendor_id alone would leave store_id/cart_id
     * pointing at this factory's own internally-generated vendor instead.
     */
    public function forVendor(Vendor $vendor, ?Store $store = null): static
    {
        return $this->state(function () use ($vendor, $store) {
            $store ??= Store::factory()->create(['vendor_id' => $vendor->id]);
            $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
            $cart = Cart::factory()->create([
                'conversation_id' => $conversation->id,
                'vendor_id' => $vendor->id,
                'store_id' => $store->id,
            ]);

            return [
                'vendor_id' => $vendor->id,
                'store_id' => $store->id,
                'conversation_id' => $conversation->id,
                'cart_id' => $cart->id,
            ];
        });
    }
}
