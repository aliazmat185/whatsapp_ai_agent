<?php

namespace Tests\Unit\Services;

use App\Models\Conversation;
use App\Models\Store;
use App\Models\StoreLocation;
use App\Models\Vendor;
use App\Services\Ai\ConversationStateMachine;
use App\Services\Store\StoreLocatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private function machine(): ConversationStateMachine
    {
        return new ConversationStateMachine(new StoreLocatorService());
    }

    public function test_single_store_vendor_auto_resolves_and_advances_to_browsing(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        StoreLocation::factory()->create(['store_id' => $store->id]);

        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id]);

        $ready = $this->machine()->resolveStoreOrRequestLocation($conversation);

        $this->assertTrue($ready);
        $this->assertSame($store->id, $conversation->fresh()->store_id);
        $this->assertSame('browsing', $this->machine()->stateFor($conversation)->current_step);
    }

    public function test_multi_store_vendor_without_location_blocks_and_requests_location(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        Store::factory()->count(2)->create(['vendor_id' => $vendor->id])
            ->each(fn ($store) => StoreLocation::factory()->create(['store_id' => $store->id]));

        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id]);

        $ready = $this->machine()->resolveStoreOrRequestLocation($conversation);

        $this->assertFalse($ready);
        $this->assertNull($conversation->fresh()->store_id);
        $this->assertSame('awaiting_location', $this->machine()->stateFor($conversation)->current_step);
    }

    public function test_multi_store_vendor_with_location_resolves_nearest_store(): void
    {
        $vendor = Vendor::factory()->approved()->create();

        $near = Store::factory()->create(['vendor_id' => $vendor->id]);
        StoreLocation::factory()->create(['store_id' => $near->id, 'latitude' => 24.861, 'longitude' => 67.011]);

        $far = Store::factory()->create(['vendor_id' => $vendor->id]);
        StoreLocation::factory()->create(['store_id' => $far->id, 'latitude' => 25.40, 'longitude' => 68.35]);

        $conversation = Conversation::factory()->create([
            'vendor_id' => $vendor->id,
            'customer_lat' => 24.86,
            'customer_lng' => 67.01,
        ]);

        $ready = $this->machine()->resolveStoreOrRequestLocation($conversation);

        $this->assertTrue($ready);
        $this->assertSame($near->id, $conversation->fresh()->store_id);
    }

    public function test_already_resolved_store_skips_relocation(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $storeA = Store::factory()->create(['vendor_id' => $vendor->id]);
        $storeB = Store::factory()->create(['vendor_id' => $vendor->id]);
        StoreLocation::factory()->create(['store_id' => $storeB->id]);

        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $storeA->id]);

        $ready = $this->machine()->resolveStoreOrRequestLocation($conversation);

        $this->assertTrue($ready);
        $this->assertSame($storeA->id, $conversation->fresh()->store_id);
    }

    public function test_escalate_sets_needs_attention_and_support_escalation_step(): void
    {
        $conversation = Conversation::factory()->create();

        $this->machine()->escalate($conversation);

        $this->assertSame('needs_attention', $conversation->fresh()->status);
        $this->assertSame('support_escalation', $this->machine()->stateFor($conversation)->current_step);
    }

    public function test_context_merges_without_overwriting_existing_keys(): void
    {
        $conversation = Conversation::factory()->create();

        $this->machine()->updateContext($conversation, ['a' => 1]);
        $this->machine()->updateContext($conversation, ['b' => 2]);

        $context = $this->machine()->stateFor($conversation)->context;

        $this->assertSame(['a' => 1, 'b' => 2], $context);
    }
}
