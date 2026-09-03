<?php

namespace App\Services\Catalog;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Store-scoped product search used by the AI agent's search_products tool
 * (PLAN.md §6.1) and any future customer-facing search endpoint. Never
 * trusts Claude's own idea of price/stock — always queries fresh.
 */
class ProductSearchService
{
    public function search(
        int $storeId,
        ?string $query = null,
        ?int $categoryId = null,
        ?float $priceMin = null,
        ?float $priceMax = null,
        int $limit = 10,
    ): Collection {
        return Product::withoutGlobalScope('vendor')
            ->with(['images', 'inventories' => fn ($q) => $q->where('store_id', $storeId)])
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->when($query, fn ($q) => $q->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%")
                    ->orWhere('ai_search_keywords', 'like', "%{$query}%");
            }))
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when($priceMin !== null, fn ($q) => $q->where('base_price', '>=', $priceMin))
            ->when($priceMax !== null, fn ($q) => $q->where('base_price', '<=', $priceMax))
            ->limit($limit)
            ->get()
            ->map(function (Product $product) {
                $inventory = $product->inventories->first();

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'price' => (float) $product->base_price,
                    'in_stock' => $inventory === null || $inventory->isInStock(),
                    'quantity_available' => $inventory?->quantity,
                ];
            });
    }
}
