<?php

namespace App\Services\Commerce;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Conversation;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * PLAN.md §8 Checkout and Payment Flow. Cart totals are always computed
 * live from cart_items (Cart::subtotal()) — never trust a stale cached
 * total, since stock/price can change between add and checkout.
 */
class CartService
{
    public function getOrCreateOpenCart(Conversation $conversation): Cart
    {
        $existing = $conversation->activeCart();

        if ($existing) {
            return $existing;
        }

        if (! $conversation->store_id) {
            throw new InvalidArgumentException('Conversation has no resolved store — cannot create a cart.');
        }

        return Cart::create([
            'conversation_id' => $conversation->id,
            'vendor_id' => $conversation->vendor_id,
            'store_id' => $conversation->store_id,
            'status' => 'open',
        ]);
    }

    public function addItem(Cart $cart, int $productId, int $quantity, ?int $variantId = null): CartItem
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }

        $product = Product::withoutGlobalScope('vendor')
            ->where('id', $productId)
            ->where('store_id', $cart->store_id)
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

        $existing = $cart->items()
            ->where('product_id', $product->id)
            ->where('product_variant_id', $variantId)
            ->first();

        if ($existing) {
            $existing->update(['quantity' => $existing->quantity + $quantity]);

            return $existing->fresh();
        }

        return CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'product_variant_id' => $variantId,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
        ]);
    }

    /**
     * Adds several products in one transaction — used by the multi-select
     * WhatsApp Flow submission, where a customer picks N items at once and
     * they must land together or not at all.
     *
     * @param  array<int, array{product_id: int, quantity?: int, variant_id?: int|null}>  $items
     * @return Collection<int, CartItem>
     */
    public function addItems(Cart $cart, array $items): Collection
    {
        return DB::transaction(fn () => collect($items)->map(
            fn (array $item) => $this->addItem($cart, (int) $item['product_id'], (int) ($item['quantity'] ?? 1), isset($item['variant_id']) ? (int) $item['variant_id'] : null)
        ));
    }

    /**
     * Re-syncs every cart item's unit_price to its product's/variant's
     * CURRENT price. CartItem.unit_price is a snapshot taken at add-to-cart
     * time — checkout must never quote or charge a stale price if it
     * changed since, so this runs before every checkout quote/confirm.
     */
    public function refreshPrices(Cart $cart): Cart
    {
        $cart->loadMissing('items.product', 'items.variant');

        foreach ($cart->items as $item) {
            $unitPrice = (float) $item->product->base_price;

            if ($item->product_variant_id && $item->variant) {
                $unitPrice += (float) $item->variant->price_delta;
            }

            if ((float) $item->unit_price !== $unitPrice) {
                $item->update(['unit_price' => $unitPrice]);
            }
        }

        return $cart->fresh(['items.product']);
    }

    public function updateQuantity(CartItem $item, int $quantity): void
    {
        if ($quantity < 1) {
            $item->delete();

            return;
        }

        $item->update(['quantity' => $quantity]);
    }

    public function removeItem(CartItem $item): void
    {
        $item->delete();
    }

    public function emptyCart(Cart $cart): void
    {
        $cart->items()->delete();
    }

    public function abandon(Cart $cart): void
    {
        $cart->update(['status' => 'abandoned']);
    }
}
