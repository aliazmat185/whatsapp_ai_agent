<?php

namespace App\Services\WhatsApp;

use App\Models\Product;
use App\Support\LogMasker;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Pushes vendor products into the single platform-wide Meta Product Catalog
 * (see config/whatsapp.php `catalog_id`) so they surface in WhatsApp catalog
 * messages. One shared catalog across all vendors — items are namespaced per
 * vendor via retailer_id, since the platform runs one shared WABA.
 */
class WhatsAppCatalogService
{
    public function upsertProduct(Product $product): Response
    {
        return $this->batch([[
            'method' => 'UPDATE',
            'data' => $this->itemData($product),
        ]]);
    }

    public function deleteProduct(Product $product): Response
    {
        return $this->batch([[
            'method' => 'DELETE',
            'data' => ['retailer_id' => $this->retailerId($product)],
        ]]);
    }

    public function retailerId(Product $product): string
    {
        return "v{$product->vendor_id}-p{$product->id}";
    }

    private function itemData(Product $product): array
    {
        $primaryImage = $product->images->first();
        $currency = $product->vendor->default_currency ?? config('commerce.default_currency');

        return array_filter([
            'retailer_id' => $this->retailerId($product),
            'name' => $product->name,
            'description' => $product->description ?: $product->name,
            'price' => number_format((float) $product->base_price, 2, '.', '')." {$currency}",
            'currency' => $currency,
            'availability' => $product->is_active ? 'in stock' : 'out of stock',
            'condition' => 'new',
            'image_url' => $primaryImage ? Storage::disk('public')->url($primaryImage->path) : null,
            'brand' => $product->vendor->business_name,
        ], fn ($value) => $value !== null);
    }

    private function batch(array $requests): Response
    {
        $response = $this->graph()->post("/{$this->catalogId()}/items_batch", [
            'item_type' => 'PRODUCT_ITEM',
            'requests' => $requests,
        ]);

        if (! $response->successful()) {
            Log::warning('WhatsApp catalog sync failed', ['response' => LogMasker::mask($response->json() ?? [])]);
        }

        return $response;
    }

    private function graph()
    {
        $baseUrl = config('whatsapp.graph_base_url');
        $apiVersion = config('whatsapp.api_version');

        return Http::withToken(config('whatsapp.system_user_token'))
            ->baseUrl("{$baseUrl}/{$apiVersion}");
    }

    private function catalogId(): string
    {
        return (string) config('whatsapp.catalog_id');
    }
}
