<?php

namespace App\Observers;

use App\Jobs\WhatsApp\CatalogSyncJob;
use App\Models\ProductImage;

/**
 * Product images live on their own table, so adding/removing one doesn't
 * touch the products row itself and ProductObserver never fires — re-sync
 * the catalog item here instead, since image_url is a catalog field.
 */
class ProductImageObserver
{
    public function created(ProductImage $image): void
    {
        CatalogSyncJob::dispatch($image->product_id);
    }

    public function deleted(ProductImage $image): void
    {
        CatalogSyncJob::dispatch($image->product_id);
    }
}
