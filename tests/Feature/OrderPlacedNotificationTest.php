<?php

namespace Tests\Feature;

use App\Events\OrderPlaced;
use App\Listeners\NotifyVendorOfNewOrder;
use App\Models\Conversation;
use App\Models\Notification;
use App\Models\Product;
use App\Models\Store;
use App\Models\Vendor;
use App\Models\VendorPackage;
use App\Models\WhatsappAccount;
use App\Services\Commerce\CartService;
use App\Services\Commerce\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderPlacedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function orderedCart(Vendor $vendor, Store $store, Conversation $conversation)
    {
        $product = Product::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id, 'base_price' => 500]);
        $cartService = new CartService();
        $cart = $cartService->getOrCreateOpenCart($conversation);
        $cartService->addItem($cart, $product->id, 1);

        return $cart->fresh();
    }

    public function test_placing_order_creates_dashboard_notification(): void
    {
        $package = VendorPackage::factory()->create(['enabled_payment_methods' => ['cod']]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $cart = $this->orderedCart($vendor, $store, $conversation);

        $order = app(CheckoutService::class)->createOrder($cart, 'cod', 'Ali', 'Test Address');

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => Vendor::class,
            'notifiable_id' => $vendor->id,
            'type' => 'new_order',
            'channel' => 'dashboard',
        ]);

        $notification = Notification::where('notifiable_id', $vendor->id)->where('channel', 'dashboard')->first();
        $this->assertSame($order->order_number, $notification->payload['order_number']);
    }

    public function test_vendor_without_notification_phone_gets_dashboard_notification_only(): void
    {
        $package = VendorPackage::factory()->create(['enabled_payment_methods' => ['cod']]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id, 'notification_phone' => null]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $cart = $this->orderedCart($vendor, $store, $conversation);

        Http::fake();

        app(CheckoutService::class)->createOrder($cart, 'cod', 'Ali', 'Test Address');

        Http::assertNothingSent();
        $this->assertSame(1, Notification::where('notifiable_id', $vendor->id)->count());
    }

    public function test_vendor_with_notification_phone_and_active_account_gets_whatsapp_alert(): void
    {
        $package = VendorPackage::factory()->create(['enabled_payment_methods' => ['cod']]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id, 'notification_phone' => '923001112222']);
        WhatsappAccount::factory()->create(['vendor_id' => $vendor->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $cart = $this->orderedCart($vendor, $store, $conversation);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ALERT1']]], 200)]);

        $order = app(CheckoutService::class)->createOrder($cart, 'cod', 'Ali', 'Test Address');

        Http::assertSent(function ($request) use ($order) {
            return str_contains($request->url(), 'graph.facebook.com')
                && str_contains(json_encode($request->data()), $order->order_number);
        });

        $this->assertSame(2, Notification::where('notifiable_id', $vendor->id)->count()); // dashboard + whatsapp
    }

    public function test_listener_does_not_throw_when_whatsapp_send_fails(): void
    {
        $package = VendorPackage::factory()->create(['enabled_payment_methods' => ['cod']]);
        $vendor = Vendor::factory()->approved()->create(['vendor_package_id' => $package->id, 'notification_phone' => '923001112222']);
        WhatsappAccount::factory()->create(['vendor_id' => $vendor->id]);
        $store = Store::factory()->create(['vendor_id' => $vendor->id]);
        $conversation = Conversation::factory()->create(['vendor_id' => $vendor->id, 'store_id' => $store->id]);
        $cart = $this->orderedCart($vendor, $store, $conversation);

        Http::fake(['graph.facebook.com/*' => Http::response(['error' => 'boom'], 500)]);

        // Should not throw despite the failed WA send.
        $order = app(CheckoutService::class)->createOrder($cart, 'cod', 'Ali', 'Test Address');

        $this->assertNotNull($order->order_number);
    }
}
