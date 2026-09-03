<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Models\Notification;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\LogMasker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * PLAN.md §9 Order delivery to vendor. Notifies the vendor via WhatsApp
 * (their own notification_phone, A10) and always creates a dashboard
 * notification regardless of whether the WA send succeeds.
 */
class NotifyVendorOfNewOrder implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public function __construct(
        private WhatsAppService $whatsApp,
    ) {}

    public function handle(OrderPlaced $event): void
    {
        $order = $event->order->loadMissing(['vendor.whatsappAccount', 'store']);
        $vendor = $order->vendor;

        Notification::create([
            'notifiable_type' => $vendor::class,
            'notifiable_id' => $vendor->id,
            'type' => 'new_order',
            'channel' => 'dashboard',
            'payload' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'store_name' => $order->store->name,
                'total' => (float) $order->total,
            ],
        ]);

        $this->sendWhatsAppAlert($order, $vendor);
    }

    private function sendWhatsAppAlert($order, $vendor): void
    {
        $account = $vendor->whatsappAccount;

        if (! $vendor->notification_phone || ! $account || ! $account->isActive()) {
            return;
        }

        $body = "New order {$order->order_number} at {$order->store->name}! Total: {$vendor->default_currency} ".number_format((float) $order->total, 2);

        try {
            $response = $this->whatsApp->sendText($account, $vendor->notification_phone, $body);

            Notification::create([
                'notifiable_type' => $vendor::class,
                'notifiable_id' => $vendor->id,
                'type' => 'new_order',
                'channel' => 'whatsapp',
                'payload' => ['order_number' => $order->order_number, 'sent' => $response->successful()],
            ]);

            if (! $response->successful()) {
                Log::warning('Vendor new-order WhatsApp alert failed', ['order_id' => $order->id, 'response' => LogMasker::mask($response->json() ?? [])]);
            }
        } catch (\Throwable $e) {
            Log::error('Vendor new-order WhatsApp alert threw', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
