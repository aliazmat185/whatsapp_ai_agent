<?php

namespace App\Services\Commerce;

use App\Events\OrderPlaced;
use App\Models\Cart;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SystemSetting;
use App\Services\Payment\PaymentService;
use Illuminate\Support\Facades\DB;

/**
 * PLAN.md §8 Checkout and Payment Flow / §13 Validation Rules. Re-checks
 * everything at checkout time, not just at add-to-cart time — stock,
 * product/store/vendor active state, and payment method eligibility can
 * all have changed since items were added.
 */
class CheckoutService
{
    public function __construct(
        private OrderService $orderService,
        private PaymentService $paymentService,
    ) {}

    /**
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validate(Cart $cart, string $paymentMethod): array
    {
        $errors = [];

        $cart->loadMissing(['items.product', 'store', 'vendor']);

        if ($cart->items->isEmpty()) {
            $errors[] = 'Your cart is empty.';

            return ['valid' => false, 'errors' => $errors];
        }

        if (! $cart->store->is_active) {
            $errors[] = 'This store is currently unavailable.';
        }

        if (! $cart->vendor->isApproved()) {
            $errors[] = 'This shop is currently unavailable.';
        }

        foreach ($cart->items as $item) {
            if (! $item->product->is_active) {
                $errors[] = "{$item->product->name} is no longer available.";

                continue;
            }

            $inventory = Inventory::where('store_id', $cart->store_id)
                ->where('product_id', $item->product_id)
                ->where('product_variant_id', $item->product_variant_id)
                ->first();

            if ($inventory && ! $inventory->isInStock($item->quantity)) {
                $errors[] = "{$item->product->name} only has {$inventory->quantity} left in stock.";
            }
        }

        if (! $this->isPaymentMethodAvailable($cart, $paymentMethod)) {
            $errors[] = 'This payment method is not available.';
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    /**
     * A payment method must be BOTH included in the vendor's package AND
     * globally enabled by admin (system_settings) to be usable — package
     * grants eligibility, admin can still kill-switch a method platform-wide
     * (e.g. a gateway outage). PLAN.md §12/§9 Admin package requirements.
     */
    public function isPaymentMethodAvailable(Cart $cart, string $paymentMethod): bool
    {
        $allowedByPackage = in_array($paymentMethod, $cart->vendor->package->enabled_payment_methods ?? [], true);

        if (! $allowedByPackage) {
            return false;
        }

        return (bool) SystemSetting::get("payment_methods.{$paymentMethod}.enabled", true);
    }

    /**
     * Creates the order from a validated cart and initiates payment
     * collection. Caller must have already called validate() and confirmed
     * $result['valid'] === true.
     */
    public function createOrder(
        Cart $cart,
        string $paymentMethod,
        string $customerName,
        string $customerAddress,
        ?float $customerLat = null,
        ?float $customerLng = null,
        float $deliveryFee = 0.0,
    ): Order {
        $order = DB::transaction(function () use ($cart, $paymentMethod, $customerName, $customerAddress, $customerLat, $customerLng, $deliveryFee) {
            $cart->loadMissing('items.product');

            $subtotal = $cart->subtotal();

            $order = Order::create([
                'order_number' => $this->orderService->generateOrderNumber(),
                'vendor_id' => $cart->vendor_id,
                'store_id' => $cart->store_id,
                'conversation_id' => $cart->conversation_id,
                'cart_id' => $cart->id,
                'customer_phone' => $cart->conversation->customer_phone,
                'customer_name' => $customerName,
                'customer_address' => $customerAddress,
                'customer_lat' => $customerLat,
                'customer_lng' => $customerLng,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'total' => $subtotal + $deliveryFee,
                'payment_method' => $paymentMethod,
                'payment_status' => 'pending',
                'status' => 'pending',
            ]);

            foreach ($cart->items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'product_name_snapshot' => $item->product->name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->lineTotal(),
                ]);

                $inventory = Inventory::where('store_id', $cart->store_id)
                    ->where('product_id', $item->product_id)
                    ->where('product_variant_id', $item->product_variant_id)
                    ->where('track_stock', true)
                    ->first();

                $inventory?->decrement('quantity', $item->quantity);
            }

            $cart->update(['status' => 'converted']);

            return $order;
        });

        $this->paymentService->initiateForOrder($order);

        $order = $order->fresh();

        OrderPlaced::dispatch($order);

        return $order;
    }
}
