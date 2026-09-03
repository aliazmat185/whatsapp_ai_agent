<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessInboundWhatsAppMessage;
use App\Jobs\RunConversationAgent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Vendor;
use App\Models\WebhookLog;
use App\Models\WhatsappAccount;
use App\Services\Commerce\CartService;
use App\Services\WhatsApp\WhatsAppPayloadParser;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessInboundWhatsAppMessageTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $phoneNumberId, string $messageId, string $from = '923001234567'): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'contacts' => [['profile' => ['name' => 'Ali Customer']]],
                        'messages' => [[
                            'id' => $messageId,
                            'from' => $from,
                            'type' => 'text',
                            'text' => ['body' => 'Hi there'],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function logFor(array $payload, ?string $waMessageId = null): WebhookLog
    {
        return WebhookLog::create([
            'source' => 'whatsapp',
            'wa_message_id' => $waMessageId,
            'raw_payload' => $payload,
            'processing_status' => 'received',
            'received_at' => now(),
        ]);
    }

    private function process(WebhookLog $log): void
    {
        (new ProcessInboundWhatsAppMessage($log->id))->handle(app(WhatsAppPayloadParser::class), app(WhatsAppService::class), app(CartService::class));
    }

    public function test_creates_conversation_and_message_and_dispatches_agent(): void
    {
        Queue::fake();

        $vendor = Vendor::factory()->approved()->create();
        WhatsappAccount::factory()->create(['vendor_id' => $vendor->id, 'phone_number_id' => '1111']);
        $log = $this->logFor($this->payload('1111', 'wamid.IN1'), 'wamid.IN1');

        $this->process($log);

        $conversation = Conversation::where('vendor_id', $vendor->id)->first();
        $this->assertNotNull($conversation);
        $this->assertSame('923001234567', $conversation->customer_phone);
        $this->assertSame('Ali Customer', $conversation->customer_name);

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversation->id,
            'wa_message_id' => 'wamid.IN1',
            'direction' => 'inbound',
        ]);

        $this->assertDatabaseHas('conversation_states', [
            'conversation_id' => $conversation->id,
            'current_step' => 'greeting',
        ]);

        Queue::assertPushed(RunConversationAgent::class, fn ($job) => $job->conversationId === $conversation->id);

        $this->assertSame('processed', $log->fresh()->processing_status);
    }

    public function test_duplicate_wa_message_id_does_not_create_second_message(): void
    {
        Queue::fake();

        $vendor = Vendor::factory()->approved()->create();
        WhatsappAccount::factory()->create(['vendor_id' => $vendor->id, 'phone_number_id' => '2222']);

        $this->process($this->logFor($this->payload('2222', 'wamid.DUP'), 'wamid.DUP'));
        $log2 = $this->logFor($this->payload('2222', 'wamid.DUP'), 'wamid.DUP');
        $this->process($log2);

        $this->assertSame(1, ConversationMessage::where('wa_message_id', 'wamid.DUP')->count());
        $this->assertSame('ignored_duplicate', $log2->fresh()->processing_status);
    }

    public function test_unresolved_vendor_fails_the_log_without_creating_conversation(): void
    {
        $log = $this->logFor($this->payload('unknown-number-id', 'wamid.UNKNOWN'), 'wamid.UNKNOWN');

        $this->process($log);

        $this->assertSame('failed', $log->fresh()->processing_status);
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_inactive_whatsapp_account_short_circuits(): void
    {
        $vendor = Vendor::factory()->approved()->create();
        WhatsappAccount::factory()->inactive()->create(['vendor_id' => $vendor->id, 'phone_number_id' => '3333']);

        $log = $this->logFor($this->payload('3333', 'wamid.INACTIVE'), 'wamid.INACTIVE');

        $this->process($log);

        $this->assertSame('failed', $log->fresh()->processing_status);
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_unapproved_vendor_short_circuits_even_with_active_account(): void
    {
        $vendor = Vendor::factory()->create(['status' => 'suspended']);
        WhatsappAccount::factory()->create(['vendor_id' => $vendor->id, 'phone_number_id' => '4444']);

        $log = $this->logFor($this->payload('4444', 'wamid.SUSPENDED'), 'wamid.SUSPENDED');

        $this->process($log);

        $this->assertSame('failed', $log->fresh()->processing_status);
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_second_message_reuses_same_conversation(): void
    {
        Queue::fake();

        $vendor = Vendor::factory()->approved()->create();
        WhatsappAccount::factory()->create(['vendor_id' => $vendor->id, 'phone_number_id' => '5555']);

        $this->process($this->logFor($this->payload('5555', 'wamid.FIRST'), 'wamid.FIRST'));
        $this->process($this->logFor($this->payload('5555', 'wamid.SECOND'), 'wamid.SECOND'));

        $this->assertSame(1, Conversation::where('vendor_id', $vendor->id)->count());
    }

    /**
     * PLAN.md §14 — "Customer sends unsupported message type (sticker,
     * voice note) → Politely respond, no AI call wasted."
     */
    public function test_unsupported_message_type_gets_canned_reply_no_conversation_created(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

        $vendor = Vendor::factory()->approved()->create();
        WhatsappAccount::factory()->create(['vendor_id' => $vendor->id, 'phone_number_id' => '6666']);

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => '6666'],
                        'messages' => [[
                            'id' => 'wamid.STICKER1',
                            'from' => '923001234567',
                            'type' => 'sticker',
                            'sticker' => ['id' => 'abc123'],
                        ]],
                    ],
                ]],
            ]],
        ];
        $log = $this->logFor($payload, 'wamid.STICKER1');

        $this->process($log);

        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('conversation_messages', 0);
        $this->assertSame('processed', $log->fresh()->processing_status);

        Queue::assertNotPushed(RunConversationAgent::class);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com'));
    }
}
