<?php

namespace Tests\Unit\Services;

use App\Models\Order;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cod_payment_initiates_as_pending(): void
    {
        $order = Order::factory()->create(['payment_method' => 'cod', 'total' => 1000]);

        $payment = app(PaymentService::class)->initiateForOrder($order);

        $this->assertSame('cod', $payment->method);
        $this->assertSame('pending', $payment->status);
        $this->assertSame(1000.0, (float) $payment->amount);
        $this->assertNull($payment->gateway_reference);
    }

    public function test_stub_gateway_records_reference(): void
    {
        $order = Order::factory()->create(['payment_method' => 'jazzcash', 'total' => 500]);

        $payment = app(PaymentService::class)->initiateForOrder($order);

        $this->assertSame('jazzcash', $payment->method);
        $this->assertStringStartsWith('STUB-JAZZCASH-', $payment->gateway_reference);
    }

    public function test_initiating_payment_logs_transaction(): void
    {
        $order = Order::factory()->create(['payment_method' => 'cod', 'total' => 1000]);

        $payment = app(PaymentService::class)->initiateForOrder($order);

        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $payment->id,
            'event_type' => 'initiated',
        ]);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $payment->id,
            'event_type' => 'confirmed',
        ]);
    }

    public function test_mark_paid_updates_payment_and_order(): void
    {
        $order = Order::factory()->create(['payment_method' => 'cod', 'total' => 1000, 'payment_status' => 'pending']);
        $payment = app(PaymentService::class)->initiateForOrder($order);

        $updated = app(PaymentService::class)->markPaid($payment);

        $this->assertSame('paid', $updated->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_unknown_payment_method_throws(): void
    {
        // payment_method is a DB enum (cod/jazzcash/easypaisa/card), so an
        // invalid value can't reach the DB — build an unpersisted Order
        // instance to exercise PaymentService's own gateway-resolution guard.
        $order = new Order(['payment_method' => 'bitcoin', 'total' => 100]);

        $this->expectException(\InvalidArgumentException::class);

        app(PaymentService::class)->initiateForOrder($order);
    }
}
