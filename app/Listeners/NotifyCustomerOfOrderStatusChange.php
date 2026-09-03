<?php

namespace App\Listeners;

use App\Events\OrderStatusChanged;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\LogMasker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors NotifyVendorOfNewOrder, but tells the customer (via the vendor's
 * own WhatsApp number, since that's who they're already chatting with) when
 * the vendor moves their order forward.
 */
class NotifyCustomerOfOrderStatusChange implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    // Laravel's queue is at-least-once: a slow-but-successful send can still
    // get picked up again by another worker once retry_after elapses, and
    // this listener has no DB row (unlike RunConversationAgent's
    // in_reply_to_id check) to tell a retry apart from a genuine resend —
    // without this, the customer gets the same "order is preparing" text
    // twice. One cache key per order+status makes a repeat a no-op.
    private const DEDUP_TTL_SECONDS = 3600;

    private const MESSAGES = [
        'confirmed' => 'Your order #:order has been confirmed and will be prepared shortly.',
        'preparing' => 'Good news — your order #:order is now being prepared.',
        'out_for_delivery' => 'Your order #:order is out for delivery.',
        'completed' => 'Your order #:order has been delivered. Enjoy!',
        'cancelled' => 'Your order #:order has been cancelled.',
    ];

    public function __construct(
        private WhatsAppService $whatsApp,
    ) {}

    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order->loadMissing('vendor.whatsappAccount');
        $account = $order->vendor->whatsappAccount;

        $template = self::MESSAGES[$order->status] ?? null;

        if (! $template || ! $order->customer_phone || ! $account || ! $account->isActive()) {
            return;
        }

        $dedupKey = "order-status-notified:{$order->id}:{$order->status}";

        if (! Cache::add($dedupKey, true, self::DEDUP_TTL_SECONDS)) {
            return;
        }

        $body = str_replace(':order', $order->order_number, $template);

        try {
            $response = $this->whatsApp->sendText($account, $order->customer_phone, $body);

            if (! $response->successful()) {
                // Didn't actually reach the customer — let a retry (or the
                // next status transition landing on the same key, unlikely
                // but not our problem here) try again instead of dropping it.
                Cache::forget($dedupKey);

                Log::warning('Customer order-status WhatsApp notification failed', [
                    'order_id' => $order->id,
                    'response' => LogMasker::mask($response->json() ?? []),
                ]);
            }
        } catch (\Throwable $e) {
            Cache::forget($dedupKey);

            Log::error('Customer order-status WhatsApp notification threw', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
