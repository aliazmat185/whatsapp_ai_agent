<?php

namespace Tests\Feature\Ai;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Product;
use App\Models\Store;
use App\Models\Vendor;
use App\Services\Ai\ClaudeAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClaudeAgentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function inboundMessage(Conversation $conversation, string $text): ConversationMessage
    {
        return ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'wa_message_id' => 'wamid.'.uniqid(),
            'content' => ['text' => $text],
        ]);
    }

    public function test_final_text_response_is_returned_and_logged(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Hello! How can I help?']],
            ], 200),
        ]);

        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $message = $this->inboundMessage($conversation, 'Hi');

        $reply = app(ClaudeAgentService::class)->handle($conversation, $message);

        $this->assertSame('Hello! How can I help?', $reply);
        $this->assertDatabaseHas('ai_routing_logs', [
            'conversation_id' => $conversation->id,
            'conversation_message_id' => $message->id,
        ]);
    }

    public function test_tool_use_is_executed_and_result_fed_back(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'name' => 'Fast Charger']);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $message = $this->inboundMessage($conversation, 'Do you have chargers?');

        Http::fakeSequence()
            ->push([
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'tool_1',
                    'name' => 'search_products',
                    'input' => ['query' => 'charger'],
                ]],
            ], 200)
            ->push([
                'content' => [['type' => 'text', 'text' => 'Yes! We have a Fast Charger in stock.']],
            ], 200);

        $reply = app(ClaudeAgentService::class)->handle($conversation, $message);

        $this->assertSame('Yes! We have a Fast Charger in stock.', $reply);

        $log = \App\Models\AiRoutingLog::where('conversation_id', $conversation->id)->first();
        $this->assertSame('product_search', $log->detected_intent);
    }

    public function test_escalation_tool_marks_log_as_escalated(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $message = $this->inboundMessage($conversation, 'I want a refund for something complicated');

        Http::fakeSequence()
            ->push([
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'tool_1',
                    'name' => 'escalate_to_human',
                    'input' => ['reason' => 'refund request'],
                ]],
            ], 200)
            ->push([
                'content' => [['type' => 'text', 'text' => 'I\'ve flagged this for the shop owner.']],
            ], 200);

        app(ClaudeAgentService::class)->handle($conversation, $message);

        $this->assertDatabaseHas('ai_routing_logs', [
            'conversation_id' => $conversation->id,
            'escalated' => true,
        ]);
        $this->assertSame('needs_attention', $conversation->fresh()->status);
    }

    public function test_api_failure_returns_fallback_and_escalates(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'server error'], 500)]);

        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $message = $this->inboundMessage($conversation, 'Hi');

        $reply = app(ClaudeAgentService::class)->handle($conversation, $message);

        $this->assertStringContainsString('delay', $reply);
        $this->assertDatabaseHas('ai_routing_logs', ['conversation_id' => $conversation->id, 'escalated' => true]);
    }

    public function test_second_turn_remembers_product_found_in_first_turn(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'name' => 'Chicken Biryani', 'base_price' => 400]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        // Turn 1: customer asks about biryani, AI searches and quotes it.
        // Turn 2: a brand new job/request — no in-memory state carries over,
        // only what's persisted (this is what previously broke: the AI had
        // no way to resolve "yes" to a product_id and looped re-quoting).
        // Both turns' fake responses are queued upfront since Http::fakeSequence()
        // appends a new '*' stub rather than replacing the previous one, so a
        // second call mid-test would never be reached (the first, now-exhausted
        // sequence throws before Laravel tries the next stub).
        Http::fakeSequence()
            ->push([
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'tool_1',
                    'name' => 'search_products',
                    'input' => ['query' => 'biryani'],
                ]],
            ], 200)
            ->push([
                'content' => [['type' => 'text', 'text' => 'Chicken Biryani - PKR 400.']],
            ], 200)
            ->push([
                'content' => [['type' => 'text', 'text' => 'placeholder']],
            ], 200);

        $message1 = $this->inboundMessage($conversation, 'Half plate biryani kitne ki hai?');
        $reply1 = app(ClaudeAgentService::class)->handle($conversation, $message1);
        $this->assertSame('Chicken Biryani - PKR 400.', $reply1);

        $message2 = $this->inboundMessage($conversation, 'G kr dain');
        $reply2 = app(ClaudeAgentService::class)->handle($conversation, $message2);
        $this->assertSame('placeholder', $reply2);

        $log = \App\Models\AiRoutingLog::where('conversation_id', $conversation->id)->latest('id')->first();
        $systemPrompt = $log->claude_request_payload['system'];

        $this->assertStringContainsString((string) $product->id, $systemPrompt);
        $this->assertStringContainsString('Chicken Biryani', $systemPrompt);
    }

    public function test_empty_tool_input_does_not_break_next_iteration(): void
    {
        // get_cart and request_location take no arguments, so Claude calls
        // them with input: {}. json_decode(..., true) collapses that to a
        // PHP [], which re-encodes as a JSON array on the next loop
        // iteration and gets rejected by Anthropic's API ("Input should be
        // an object") once that tool_use block is replayed in history.
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $message = $this->inboundMessage($conversation, 'What is in my cart?');

        Http::fakeSequence()
            ->push([
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'tool_1',
                    'name' => 'get_cart',
                    'input' => [],
                ]],
            ], 200)
            ->push([
                'content' => [['type' => 'text', 'text' => 'Your cart is empty.']],
            ], 200);

        $reply = app(ClaudeAgentService::class)->handle($conversation, $message);

        $this->assertSame('Your cart is empty.', $reply);
    }

    public function test_system_prompt_includes_cart_contents_on_later_turn(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'name' => 'Chicken Biryani', 'base_price' => 400]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        app(\App\Services\Ai\ToolRegistry::class)->execute('add_to_cart', ['product_id' => $product->id], $conversation);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'placeholder']],
            ], 200),
        ]);

        $message = $this->inboundMessage($conversation, 'Confirmed');
        app(ClaudeAgentService::class)->handle($conversation, $message);

        $log = \App\Models\AiRoutingLog::where('conversation_id', $conversation->id)->latest('id')->first();
        $systemPrompt = $log->claude_request_payload['system'];

        $this->assertStringContainsString('Chicken Biryani', $systemPrompt);
        $this->assertStringContainsString('do not call add_to_cart for these again', $systemPrompt);
    }

    public function test_outbound_interactive_message_history_shows_plain_text_not_selected(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        // Simulates what RunConversationAgent::sendLocationRequest persists —
        // an OUTBOUND interactive row with plain text, not a customer tap.
        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'interactive',
            'content' => ['text' => 'Please share your location.'],
            'ai_generated' => false,
        ]);

        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'placeholder']]], 200)]);

        $message = $this->inboundMessage($conversation, 'Here you go');
        app(ClaudeAgentService::class)->handle($conversation, $message);

        $log = \App\Models\AiRoutingLog::where('conversation_id', $conversation->id)->latest('id')->first();
        $historyText = json_encode($log->claude_request_payload['messages']);

        $this->assertStringContainsString('Please share your location.', $historyText);
        $this->assertStringNotContainsString('Selected:', $historyText);
    }

    public function test_checkout_context_surfaces_previously_given_info(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);

        app(\App\Services\Ai\ToolRegistry::class)->execute('add_to_cart', ['product_id' => $product->id], $conversation);
        app(\App\Services\Ai\ToolRegistry::class)->execute('start_checkout', ['customer_name' => 'Ali Azmat'], $conversation);

        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'placeholder']]], 200)]);

        $message = $this->inboundMessage($conversation, 'Gulberg, Lahore');
        app(ClaudeAgentService::class)->handle($conversation, $message);

        $log = \App\Models\AiRoutingLog::where('conversation_id', $conversation->id)->latest('id')->first();
        $systemPrompt = $log->claude_request_payload['system'];

        $this->assertStringContainsString('customer_name: Ali Azmat', $systemPrompt);
        $this->assertStringContainsString('do not ask for it again', $systemPrompt);
    }

    public function test_exceeding_max_iterations_escalates(): void
    {
        config(['anthropic.max_tool_iterations' => 2]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'tool_use',
                    'id' => 'tool_loop',
                    'name' => 'search_products',
                    'input' => ['query' => 'x'],
                ]],
            ], 200),
        ]);

        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $message = $this->inboundMessage($conversation, 'Hi');

        $reply = app(ClaudeAgentService::class)->handle($conversation, $message);

        $this->assertDatabaseHas('ai_routing_logs', ['conversation_id' => $conversation->id, 'escalated' => true]);
        $this->assertNotEmpty($reply);
    }

    public function test_active_agent_persona_and_language_appear_in_system_prompt(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Salaam!']],
            ], 200),
        ]);

        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        Agent::factory()->for($vendor)->active()->create([
            'name' => 'Sana',
            'persona' => 'Warm and casual.',
            'language' => 'Urdu',
            'sales_goal' => 'full_order_closing',
        ]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $message = $this->inboundMessage($conversation, 'Hi');

        app(ClaudeAgentService::class)->handle($conversation, $message);

        $log = \App\Models\AiRoutingLog::where('conversation_id', $conversation->id)->latest('id')->first();
        $systemPrompt = $log->claude_request_payload['system'];

        $this->assertStringContainsString('You are Sana', $systemPrompt);
        $this->assertStringContainsString('Warm and casual.', $systemPrompt);
        $this->assertStringContainsString('Default language is Urdu', $systemPrompt);
    }

    public function test_faq_only_agent_omits_checkout_tools_from_request(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Sure, here is our menu.']],
            ], 200),
        ]);

        $vendor = Vendor::factory()->approved()->create();
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        Agent::factory()->for($vendor)->active()->create(['sales_goal' => 'faq_only']);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $message = $this->inboundMessage($conversation, 'What are your hours?');

        app(ClaudeAgentService::class)->handle($conversation, $message);

        Http::assertSent(function ($request) {
            $toolNames = collect($request->data()['tools'])->pluck('name');

            return ! $toolNames->contains('add_to_cart') && ! $toolNames->contains('start_checkout');
        });
    }
}
