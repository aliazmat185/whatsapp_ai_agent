<?php

namespace App\Jobs\WhatsApp;

use App\Models\Product;
use App\Services\WhatsApp\WhatsAppCatalogService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes a product create/update/delete to the shared Meta Product Catalog.
 * Dispatched by ProductObserver/ProductImageObserver. Best-effort: failures
 * are logged, never rethrown, so a Meta/network outage can't break product
 * CRUD (see WhatsAppCatalogService).
 */
class CatalogSyncJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public int $productId,
        public string $action = 'upsert',
    ) {}

    public function handle(WhatsAppCatalogService $catalog): void
    {
        if (! config('whatsapp.catalog_id')) {
            return;
        }

        $product = Product::withoutGlobalScope('vendor')->withTrashed()->with(['images', 'vendor'])->find($this->productId);

        if (! $product) {
            return;
        }

        try {
            $response = $this->action === 'delete'
                ? $catalog->deleteProduct($product)
                : $catalog->upsertProduct($product);

            if ($response->successful()) {
                $product->update(['catalog_synced_at' => now(), 'catalog_sync_error' => null]);
            } else {
                $product->update(['catalog_sync_error' => $response->json('error.message') ?? 'Unknown error from WhatsApp catalog.']);
            }
        } catch (Throwable $e) {
            Log::error('WhatsApp catalog sync failed', ['product_id' => $product->id, 'error' => $e->getMessage()]);
            $product->update(['catalog_sync_error' => $e->getMessage()]);
        }
    }
}
