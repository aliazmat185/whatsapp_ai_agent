<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RunConversationAgent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Store;
use App\Models\StoreLocation;
use App\Models\Vendor;
use App\Models\WhatsappAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RunConversationAgentTest extends TestCase
{
    use RefreshDatabase;

    private function inboundMessage(Conversation $conversation): ConversationMessage
    {
        return ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'wa_message_id' => 'wamid.'.uniqid(),
            'content' => ['text' => 'Hi'],
        ]);
    }

    public function test_multi_store_vendor_without_location_sends_location_request_not_claude_call(): void
    {
        Http::fake(); // any call here would be a bug — asserted below

        $vendor = Vendor::factory()->approved()->create();
        WhatsappAccount::factory()->create(['vendor_id' => $vendor->id]);
        Store::factory()->count(2)->create(['vendor_id' => $vendor->id])
            ->each(fn ($store) => StoreLocation::factory()->create(['store_id' => $store->id]));

        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id]);
        $message = $this->inboundMessage($conversation);

        (new RunConversationAgent($conversation->id, $message->id))->handle(
            app(\App\Services\Ai\ConversationStateMachine::class),
            app(\App\Services\Ai\ClaudeAgentService::class),
            app(\App\Services\WhatsApp\WhatsAppService::class),
        );

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'anthropic.com'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'interactive',
        ]);
    }

    public function test_single_store_vendor_invokes_claude_and_sends_reply(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Sure, here are our products.']]], 200),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
        ]);

        $vendor = Vendor::factory()->approved()->create();
        WhatsappAccount::factory()->create(['vendor_id' => $vendor->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        StoreLocation::factory()->create(['store_id' => $store->id]);

        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id]);
        $message = $this->inboundMessage($conversation);

        (new RunConversationAgent($conversation->id, $message->id))->handle(
            app(\App\Services\Ai\ConversationStateMachine::class),
            app(\App\Services\Ai\ClaudeAgentService::class),
            app(\App\Services\WhatsApp\WhatsAppService::class),
        );

        $this->assertSame($store->id, $conversation->fresh()->store_id);

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'ai_generated' => true,
        ]);
    }

    public function test_running_twice_for_the_same_triggering_message_sends_only_one_reply(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Sure, here are our products.']]], 200),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
        ]);

        $vendor = Vendor::factory()->approved()->create();
        WhatsappAccount::factory()->create(['vendor_id' => $vendor->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        StoreLocation::factory()->create(['store_id' => $store->id]);

        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id]);
        $message = $this->inboundMessage($conversation);

        $run = fn () => (new RunConversationAgent($conversation->id, $message->id))->handle(
            app(\App\Services\Ai\ConversationStateMachine::class),
            app(\App\Services\Ai\ClaudeAgentService::class),
            app(\App\Services\WhatsApp\WhatsAppService::class),
        );

        $run();
        $run();

        $this->assertSame(1, ConversationMessage::where('in_reply_to_id', $message->id)->count());
        Http::assertSentCount(2); // one Claude call + one WhatsApp send, not two of each
    }

    public function test_missing_whatsapp_account_short_circuits_silently(): void
    {
        Http::fake();

        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        StoreLocation::factory()->create(['store_id' => $store->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id]);
        $message = $this->inboundMessage($conversation);

        (new RunConversationAgent($conversation->id, $message->id))->handle(
            app(\App\Services\Ai\ConversationStateMachine::class),
            app(\App\Services\Ai\ClaudeAgentService::class),
            app(\App\Services\WhatsApp\WhatsAppService::class),
        );

        Http::assertNothingSent();
    }
}
