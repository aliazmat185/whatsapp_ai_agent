<?php

namespace App\Services\Commerce;

use App\Events\OrderStatusChanged;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Order number generation and status lifecycle. PLAN.md §13 — status
 * transitions restricted to Order::ALLOWED_TRANSITIONS; no transitions out
 * of completed/cancelled.
 */
class OrderService
{
    public function generateOrderNumber(): string
    {
        $date = now()->format('Ymd');

        return DB::transaction(function () use ($date) {
            $prefix = "ORD-{$date}-";

            $lastSequence = Order::withoutGlobalScope('vendor')
                ->where('order_number', 'like', "{$prefix}%")
                ->lockForUpdate()
                ->orderByDesc('order_number')
                ->value('order_number');

            $nextNumber = $lastSequence
                ? ((int) substr($lastSequence, -4)) + 1
                : 1;

            return $prefix.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
        });
    }

    public function transitionStatus(Order $order, string $newStatus, ?User $actor = null): Order
    {
        if (! $order->canTransitionTo($newStatus)) {
            throw new InvalidArgumentException(
                "Cannot transition order from '{$order->status}' to '{$newStatus}'."
            );
        }

        $previousStatus = $order->status;
        $order->update(['status' => $newStatus]);

        OrderStatusChanged::dispatch($order, $previousStatus);

        return $order->fresh();
    }

    public function cancel(Order $order, string $reason): Order
    {
        if (! $order->canTransitionTo('cancelled')) {
            throw new InvalidArgumentException("Order in status '{$order->status}' cannot be cancelled.");
        }

        $previousStatus = $order->status;
        $order->update(['status' => 'cancelled', 'cancelled_reason' => $reason]);

        OrderStatusChanged::dispatch($order, $previousStatus);

        return $order->fresh();
    }

    /**
     * Vendor-initiated edits on customer request (change qty, add/remove a
     * line). Only allowed while Order::EDITABLE_STATUSES — once it's
     * preparing/shipped the physical order is already in motion. Inventory
     * is adjusted by the delta each time, never re-derived from scratch, so
     * concurrent edits/orders on the same product stay consistent.
     */
    public function updateItemQuantity(Order $order, OrderItem $item, int $quantity): Order
    {
        $this->assertEditable($order);
        $this->assertBelongsToOrder($order, $item);

        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1 — remove the item instead.');
        }

        return DB::transaction(function () use ($order, $item, $quantity) {
            $delta = $quantity - $item->quantity;

            if ($delta !== 0) {
                $this->adjustInventory($order, $item->product_id, $item->product_variant_id, -$delta);
            }

            $item->update([
                'quantity' => $quantity,
                'line_total' => $item->unit_price * $quantity,
            ]);

            return $this->recalcTotals($order);
        });
    }

    public function removeItem(Order $order, OrderItem $item): Order
    {
        $this->assertEditable($order);
        $this->assertBelongsToOrder($order, $item);

        if ($order->items()->count() <= 1) {
            throw new InvalidArgumentException('Cannot remove the only item on an order — cancel the order instead.');
        }

        return DB::transaction(function () use ($order, $item) {
            $this->adjustInventory($order, $item->product_id, $item->product_variant_id, $item->quantity);
            $item->delete();

            return $this->recalcTotals($order);
        });
    }

    public function addItem(Order $order, int $productId, int $quantity, ?int $variantId = null): Order
    {
        $this->assertEditable($order);

        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }

        return DB::transaction(function () use ($order, $productId, $quantity, $variantId) {
            $product = Product::withoutGlobalScope('vendor')
                ->where('id', $productId)
                ->where('store_id', $order->store_id)
                ->where('is_active', true)
                ->firstOrFail();

            $unitPrice = (float) $product->base_price;

            if ($variantId) {
                $variant = ProductVariant::where('id', $variantId)
                    ->where('product_id', $product->id)
                    ->where('is_active', true)
                    ->firstOrFail();

                $unitPrice += (float) $variant->price_delta;
            }

            $existing = $order->items()
                ->where('product_id', $productId)
                ->where('product_variant_id', $variantId)
                ->first();

            if ($existing) {
                return $this->updateItemQuantity($order, $existing, $existing->quantity + $quantity);
            }

            $this->adjustInventory($order, $productId, $variantId, -$quantity);

            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_variant_id' => $variantId,
                'product_name_snapshot' => $product->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $unitPrice * $quantity,
            ]);

            return $this->recalcTotals($order);
        });
    }

    private function assertEditable(Order $order): void
    {
        if (! $order->isEditable()) {
            throw new InvalidArgumentException("Order in status '{$order->status}' can no longer be edited.");
        }
    }

    private function assertBelongsToOrder(Order $order, OrderItem $item): void
    {
        if ($item->order_id !== $order->id) {
            throw new InvalidArgumentException('That item does not belong to this order.');
        }
    }

    /**
     * $delta > 0 gives stock back (qty reduced/removed), $delta < 0 takes
     * more stock (qty increased/item added). Skips untracked inventory
     * rows/products with no inventory row at all, same as checkout.
     */
    private function adjustInventory(Order $order, int $productId, ?int $variantId, int $delta): void
    {
        $inventory = Inventory::where('store_id', $order->store_id)
            ->where('product_id', $productId)
            ->where('product_variant_id', $variantId)
            ->where('track_stock', true)
            ->first();

        if (! $inventory) {
            return;
        }

        if ($delta < 0 && ! $inventory->isInStock(abs($delta))) {
            throw new InvalidArgumentException('Not enough stock available for that quantity.');
        }

        $inventory->increment('quantity', $delta);
    }

    private function recalcTotals(Order $order): Order
    {
        $order->loadMissing('items');
        $subtotal = $order->items->sum('line_total');

        $order->update([
            'subtotal' => $subtotal,
            'total' => $subtotal + $order->delivery_fee,
        ]);

        return $order->fresh('items');
    }
}
