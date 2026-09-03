<?php

namespace App\Observers;

use App\Jobs\Ai\ReindexProductJob;
use App\Jobs\WhatsApp\CatalogSyncJob;
use App\Models\Product;

/**
 * Only dispatches ReindexProductJob when a searchable field changes
 * (name/description/category/tags) — price/stock churn never touches
 * embeddings (RAG_PLAN.md).
 *
 * Separately dispatches CatalogSyncJob to push the product into the shared
 * Meta Product Catalog whenever a catalog-visible field changes.
 */
class ProductObserver
{
    private const SEARCHABLE_FIELDS = ['name', 'description', 'category_id', 'ai_search_keywords'];

    private const CATALOG_FIELDS = ['name', 'description', 'base_price', 'sku', 'is_active'];

    public function created(Product $product): void
    {
        ReindexProductJob::dispatch($product->id);
        CatalogSyncJob::dispatch($product->id);
    }

    public function updated(Product $product): void
    {
        if ($product->wasChanged(self::SEARCHABLE_FIELDS)) {
            ReindexProductJob::dispatch($product->id);
        }

        if ($product->wasChanged(self::CATALOG_FIELDS)) {
            CatalogSyncJob::dispatch($product->id);
        }
    }

    public function deleted(Product $product): void
    {
        CatalogSyncJob::dispatch($product->id, 'delete');
    }
}
