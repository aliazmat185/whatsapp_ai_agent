<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationState;
use App\Models\WebhookLog;
use App\Models\WhatsappAccount;
use App\Services\Commerce\CartService;
use App\Services\WhatsApp\WhatsAppPayloadParser;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * PLAN.md §5 Webhook Handling step 3. Resolves the vendor, finds/creates the
 * conversation, persists the message, and hands off to RunConversationAgent
 * (PLAN.md §6) for the actual AI-driven reply.
 */
class ProcessInboundWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * message_type values conversation_messages actually supports (DB
     * enum). Anything else (sticker, audio, video, document, unknown) is
     * PLAN.md §14's "unsupported message type" edge case — acknowledged
     * with a canned reply, never persisted, no AI call wasted.
     */
    private const SUPPORTED_TYPES = ['text', 'image', 'location', 'interactive', 'template', 'order'];

    public function __construct(
        public int $webhookLogId,
    ) {}

    public function handle(WhatsAppPayloadParser $parser, WhatsAppService $whatsApp, CartService $cartService): void
    {
        $log = WebhookLog::find($this->webhookLogId);

        if (! $log) {
            return;
        }

        $payload = $log->raw_payload;
        $message = $parser->parseMessage($payload);

        if (! $message) {
            // Status/delivery-receipt callback, not an actual message — nothing to do.
            $log->update(['processing_status' => 'processed']);

            return;
        }

        // Belt-and-suspenders: the controller already checked webhook_logs
        // for a duplicate wa_message_id before dispatching this job, but a
        // second webhook delivery could have been dispatched to the queue
        // in the same narrow window. The real guard is the DB unique
        // constraint on conversation_messages.wa_message_id below.
        if (ConversationMessage::where('wa_message_id', $message['wa_message_id'])->exists()) {
            $log->update(['processing_status' => 'ignored_duplicate']);

            return;
        }

        $phoneNumberId = $parser->phoneNumberId($payload);
        $account = WhatsappAccount::where('phone_number_id', $phoneNumberId)->first();

        if (! $account || ! $account->isActive() || ! $account->vendor->isApproved()) {
            $log->update(['processing_status' => 'failed', 'error_message' => 'Vendor not resolved or not active/approved.']);

            return;
        }

        if (! in_array($message['type'], self::SUPPORTED_TYPES, true)) {
            $this->replyUnsupportedType($whatsApp, $account, $message['from']);
            $log->update(['processing_status' => 'processed']);

            return;
        }

        $vendor = $account->vendor;

        try {
            [$conversation, $inboundMessage] = DB::transaction(function () use ($vendor, $message, $parser, $payload) {
                $conversation = Conversation::firstOrCreate(
                    ['vendor_id' => $vendor->id, 'customer_phone' => $message['from']],
                    ['customer_name' => $parser->senderProfileName($payload), 'status' => 'active']
                );

                if ($message['type'] === 'location' && $message['latitude'] !== null) {
                    $conversation->update([
                        'customer_lat' => $message['latitude'],
                        'customer_lng' => $message['longitude'],
                    ]);
                }

                $conversation->update(['last_message_at' => now()]);

                ConversationState::firstOrCreate(
                    ['conversation_id' => $conversation->id],
                    ['current_step' => 'greeting']
                );

                $inboundMessage = ConversationMessage::create([
                    'conversation_id' => $conversation->id,
                    'direction' => 'inbound',
                    'message_type' => $message['type'],
                    'wa_message_id' => $message['wa_message_id'],
                    'content' => $message,
                ]);

                return [$conversation, $inboundMessage];
            });
        } catch (UniqueConstraintViolationException) {
            // Two workers raced past the exists() check above — the DB
            // constraint is the final source of truth, so just stop here.
            $log->update(['processing_status' => 'ignored_duplicate']);

            return;
        }

        if ($message['flow_response'] ?? null) {
            $this->handleFlowSubmission($conversation, $message['flow_response'], $account, $whatsApp, $cartService);
            $log->update(['processing_status' => 'processed']);

            return;
        }

        RunConversationAgent::dispatch($conversation->id, $inboundMessage->id);

        $log->update(['processing_status' => 'processed']);
    }

    /**
     * A completed multi-select product Flow bypasses RunConversationAgent
     * entirely — adding items to a cart is a deterministic DB write, not
     * something worth trusting to an LLM turn. See ToolRegistry::sendProductFlow()
     * for where flow_token is minted and WhatsAppFlowController for the
     * encrypted data-exchange side of this same Flow.
     */
    private function handleFlowSubmission(Conversation $conversation, array $flowResponse, WhatsappAccount $account, WhatsAppService $whatsApp, CartService $cartService): void
    {
        try {
            $context = json_decode(Crypt::decryptString((string) ($flowResponse['flow_token'] ?? '')), true);

            if (($context['conversation_id'] ?? null) !== $conversation->id) {
                throw new \RuntimeException('flow_token does not match this conversation.');
            }

            $productIds = collect($flowResponse['selected_products'] ?? [])
                ->map(fn ($id) => (int) Str::after((string) $id, 'product:'))
                ->filter()
                ->values();

            if ($productIds->isEmpty()) {
                $whatsApp->sendText($account, $conversation->customer_phone, "You didn't select any items — tap the menu again whenever you're ready.");

                return;
            }

            $cart = $cartService->getOrCreateOpenCart($conversation);
            $cartService->addItems($cart, $productIds->map(fn ($id) => ['product_id' => $id])->all());

            $count = $productIds->count();
            $subtotal = number_format($cart->fresh()->subtotal(), 2);
            $currency = $conversation->vendor->default_currency;

            $whatsApp->sendText(
                $account,
                $conversation->customer_phone,
                "Added {$count} item(s) to your cart — subtotal {$currency} {$subtotal}. Want to check out, or keep browsing?"
            );
        } catch (Throwable $e) {
            Log::error('WhatsApp Flow submission failed', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);

            try {
                $whatsApp->sendText($account, $conversation->customer_phone, 'Sorry, something went wrong adding those items. Please try again.');
            } catch (Throwable $sendError) {
                Log::warning('Flow-submission-failure reply failed to send', ['error' => $sendError->getMessage()]);
            }
        }
    }

    private function replyUnsupportedType(WhatsAppService $whatsApp, WhatsappAccount $account, string $toPhone): void
    {
        try {
            $whatsApp->sendText($account, $toPhone, "I can help with text or location messages right now. Could you type your question instead?");
        } catch (\Throwable $e) {
            Log::warning('Unsupported-message-type reply failed to send', ['error' => $e->getMessage()]);
        }
    }
}
