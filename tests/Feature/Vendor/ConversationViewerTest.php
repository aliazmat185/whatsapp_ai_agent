<?php

namespace Tests\Feature\Vendor;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Vendor;
use App\Models\WhatsappAccount;
use App\Services\WhatsApp\WhatsAppService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ConversationViewerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function approvedVendorOwner(): Vendor
    {
        $vendor = Vendor::factory()->approved()->create();
        $vendor->owner->assignRole('vendor_owner');
        $vendor->owner->update(['vendor_id' => $vendor->id]);

        return $vendor;
    }

    public function test_vendor_sees_only_own_conversations(): void
    {
        $vendorA = $this->approvedVendorOwner();
        $vendorB = $this->approvedVendorOwner();

        Conversation::factory()->create(['vendor_id' => $vendorA->id, 'customer_name' => 'Customer A']);
        Conversation::factory()->create(['vendor_id' => $vendorB->id, 'customer_name' => 'Customer B']);

        $component = Livewire::actingAs($vendorA->owner)->test('vendor.conversation-list');

        $this->assertStringContainsString('Customer A', $component->html());
        $this->assertStringNotContainsString('Customer B', $component->html());
    }

    public function test_vendor_cannot_view_another_vendors_conversation_thread(): void
    {
        $vendorA = $this->approvedVendorOwner();
        $vendorB = $this->approvedVendorOwner();

        $conversation = Conversation::factory()->create(['vendor_id' => $vendorB->id]);

        Livewire::actingAs($vendorA->owner)
            ->test('vendor.conversation-thread', ['conversation' => $conversation])
            ->assertStatus(404);
    }

    public function test_thread_shows_messages_in_order(): void
    {
        $vendor = $this->approvedVendorOwner();
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id]);

        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'wa_message_id' => 'wamid.A',
            'content' => ['text' => 'First message'],
        ]);
        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => ['text' => 'Second message'],
        ]);

        $component = Livewire::actingAs($vendor->owner)
            ->test('vendor.conversation-thread', ['conversation' => $conversation]);

        $html = $component->html();
        $this->assertStringContainsString('First message', $html);
        $this->assertStringContainsString('Second message', $html);
        $this->assertLessThan(
            strpos($html, 'Second message'),
            strpos($html, 'First message')
        );
    }

    public function test_vendor_can_send_manual_reply(): void
    {
        $vendor = $this->approvedVendorOwner();
        WhatsappAccount::factory()->create(['vendor_id' => $vendor->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id]);

        $mockService = Mockery::mock(WhatsAppService::class);
        $mockService->shouldReceive('sendText')->once()->andReturn(Mockery::mock(Response::class));
        $this->app->instance(WhatsAppService::class, $mockService);

        Livewire::actingAs($vendor->owner)
            ->test('vendor.conversation-thread', ['conversation' => $conversation])
            ->set('reply', 'Thanks for your order!')
            ->call('sendReply')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'ai_generated' => false,
        ]);

        $message = ConversationMessage::where('conversation_id', $conversation->id)->first();
        $this->assertSame('Thanks for your order!', $message->content['text']);
    }

    public function test_manual_reply_requires_active_whatsapp_account(): void
    {
        $vendor = $this->approvedVendorOwner();
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id]);

        Livewire::actingAs($vendor->owner)
            ->test('vendor.conversation-thread', ['conversation' => $conversation])
            ->set('reply', 'Hello')
            ->call('sendReply')
            ->assertHasErrors('reply');

        $this->assertDatabaseMissing('conversation_messages', ['conversation_id' => $conversation->id]);
    }
}
