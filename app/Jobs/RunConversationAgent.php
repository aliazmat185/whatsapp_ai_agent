<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Product;
use App\Services\Ai\ClaudeAgentService;
use App\Services\Ai\ConversationStateMachine;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * PLAN.md §6.2 flow per inbound message. Runs after
 * ProcessInboundWhatsAppMessage has persisted the inbound message.
 *
 * If the vendor has multiple stores and none is resolved yet, this skips
 * the Claude call entirely and sends a location-request message instead —
 * calling the LLM to figure out "we need location" would waste a request
 * when the state machine already knows deterministically.
 *
 * ShouldBeUnique (keyed on conversation) stops two runs for the same
 * conversation from overlapping — e.g. a multi-tool-iteration Claude turn
 * that outlives the queue's retry_after window, which would otherwise let a
 * second worker pick up the same job while the first is still replying and
 * send the customer the same message twice.
 */
class RunConversationAgent implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 200;

    public function __construct(
        public int $conversationId,
        public int $triggeringMessageId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->conversationId;
    }

    public function handle(
        ConversationStateMachine $stateMachine,
        ClaudeAgentService $agent,
        WhatsAppService $whatsApp,
    ): void {
        $conversation = Conversation::find($this->conversationId);
        $triggeringMessage = ConversationMessage::find($this->triggeringMessageId);

        if (! $conversation || ! $triggeringMessage) {
            return;
        }

        // Idempotency: a retried/duplicated dispatch for a message already
        // replied to must not send the customer a second copy of the reply.
        if (ConversationMessage::where('in_reply_to_id', $this->triggeringMessageId)->exists()) {
            return;
        }

        $account = $conversation->vendor->whatsappAccount;

        if (! $account || ! $account->isActive()) {
            return;
        }

        $ready = $stateMachine->resolveStoreOrRequestLocation($conversation);

        if (! $ready) {
            $this->sendLocationRequest($whatsApp, $account, $conversation, $triggeringMessage);

            return;
        }

        $this->sendProductImageIfSelected($whatsApp, $account, $conversation, $triggeringMessage);

        $reply = $agent->handle($conversation, $triggeringMessage);

        $this->sendReply($whatsApp, $account, $conversation, $reply, $triggeringMessage);
    }

    /**
     * A tapped product row arrives as an inbound interactive message with
     * interactive_reply_id "product:<id>" — a deterministic UI event, not
     * something to leave to the AI's judgement. Sent as its own message
     * before the AI's reply so the image appears first in the chat.
     */
    private function sendProductImageIfSelected($whatsApp, $account, Conversation $conversation, ConversationMessage $triggeringMessage): void
    {
        if ($triggeringMessage->direction !== 'inbound' || $triggeringMessage->message_type !== 'interactive') {
            return;
        }

        $replyId = $triggeringMessage->content['interactive_reply_id'] ?? '';

        if (! str_starts_with($replyId, 'product:')) {
            return;
        }

        $productId = (int) substr($replyId, strlen('product:'));

        $product = Product::withoutGlobalScope('vendor')
            ->where('store_id', $conversation->store_id)
            ->with('images')
            ->find($productId);

        $image = $product?->images
            ->sortByDesc('is_primary')
            ->first();

        if (! $image) {
            return;
        }

        try {
            $whatsApp->sendImage($account, $conversation->customer_phone, $image->publicUrl(), $product->name);
        } catch (\Throwable $e) {
            Log::error('WhatsApp product image send failed', ['error' => $e->getMessage(), 'product_id' => $productId]);
        }
    }

    private function sendLocationRequest($whatsApp, $account, Conversation $conversation, ConversationMessage $triggeringMessage): void
    {
        $body = 'To show you what\'s available nearby, please share your location.';

        try {
            $whatsApp->sendLocationRequest($account, $conversation->customer_phone, $body);
        } catch (\Throwable $e) {
            Log::error('WhatsApp location request send failed', ['error' => $e->getMessage()]);
        }

        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'in_reply_to_id' => $triggeringMessage->id,
            'direction' => 'outbound',
            'message_type' => 'interactive',
            'content' => ['text' => $body],
            'ai_generated' => false,
        ]);
    }

    private function sendReply($whatsApp, $account, Conversation $conversation, string $reply, ConversationMessage $triggeringMessage): void
    {
        try {
            $whatsApp->sendText($account, $conversation->customer_phone, $reply);
        } catch (\Throwable $e) {
            Log::error('WhatsApp AI reply send failed', ['error' => $e->getMessage()]);
        }

        ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'in_reply_to_id' => $triggeringMessage->id,
            'direction' => 'outbound',
            'message_type' => 'text',
            'content' => ['text' => $reply],
            'ai_generated' => true,
        ]);
    }
}
