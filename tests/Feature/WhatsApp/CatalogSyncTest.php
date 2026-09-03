<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp.catalog_id' => 'test-catalog-id']);
    }

    /** Other jobs (e.g. ReindexProductJob's embedding call) also fire over Http::fake — filter to catalog calls only. */
    private function catalogBatchRequests(): array
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), 'items_batch'))
            ->map(fn ($pair) => $pair[0])
            ->values()
            ->all();
    }

    public function test_creating_a_product_syncs_it_to_the_whatsapp_catalog(): void
    {
        Http::fake(['*/items_batch' => Http::response(['success' => true], 200)]);

        $product = Product::factory()->create(['name' => 'Phone Charger', 'base_price' => 1500]);

        $requests = $this->catalogBatchRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('UPDATE', $requests[0]['requests'][0]['method']);
        $this->assertSame("v{$product->vendor_id}-p{$product->id}", $requests[0]['requests'][0]['data']['retailer_id']);
        $this->assertSame('Phone Charger', $requests[0]['requests'][0]['data']['name']);

        $this->assertNotNull($product->fresh()->catalog_synced_at);
        $this->assertNull($product->fresh()->catalog_sync_error);
    }

    public function test_updating_a_catalog_field_resyncs_the_product(): void
    {
        Http::fake(['*/items_batch' => Http::response(['success' => true], 200)]);

        $product = Product::factory()->create();
        $product->update(['base_price' => 999]);

        $this->assertCount(2, $this->catalogBatchRequests());
    }

    public function test_updating_a_non_catalog_field_does_not_resync(): void
    {
        Http::fake(['*/items_batch' => Http::response(['success' => true], 200)]);

        $product = Product::factory()->create();
        $product->update(['ai_search_keywords' => 'chargers, cables']);

        $this->assertCount(1, $this->catalogBatchRequests());
    }

    public function test_deleting_a_product_removes_it_from_the_catalog(): void
    {
        Http::fake(['*/items_batch' => Http::response(['success' => true], 200)]);

        $product = Product::factory()->create();
        $product->delete();

        $requests = $this->catalogBatchRequests();
        $this->assertCount(2, $requests);
        $this->assertSame('DELETE', $requests[1]['requests'][0]['method']);
        $this->assertSame("v{$product->vendor_id}-p{$product->id}", $requests[1]['requests'][0]['data']['retailer_id']);
    }

    public function test_adding_a_product_image_resyncs_the_product(): void
    {
        Http::fake(['*/items_batch' => Http::response(['success' => true], 200)]);

        $product = Product::factory()->create();
        ProductImage::create(['product_id' => $product->id, 'path' => 'product-images/charger.jpg', 'is_primary' => true]);

        $requests = $this->catalogBatchRequests();
        $this->assertCount(2, $requests);
        $this->assertStringContainsString('product-images/charger.jpg', $requests[1]['requests'][0]['data']['image_url']);
    }

    public function test_catalog_failure_is_recorded_without_throwing(): void
    {
        Http::fake(['*/items_batch' => Http::response(['error' => ['message' => 'Invalid catalog ID']], 400)]);

        $product = Product::factory()->create();

        $this->assertSame('Invalid catalog ID', $product->fresh()->catalog_sync_error);
        $this->assertNull($product->fresh()->catalog_synced_at);
    }

    public function test_no_sync_attempted_when_catalog_id_is_not_configured(): void
    {
        config(['whatsapp.catalog_id' => null]);
        Http::fake();

        Product::factory()->create();

        $this->assertCount(0, $this->catalogBatchRequests());
    }
}
