<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\DB;

/**
 * PLAN.md §7 Payment Requirements. Resolves the right gateway adapter for
 * an order's chosen payment method, creates the Payment record, and logs
 * every gateway interaction to payment_transactions (append-only audit
 * trail, separate from the Payment row's current state).
 */
class PaymentService
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways;

    public function __construct(
        CodGateway $cod,
        JazzCashGateway $jazzCash,
        EasypaisaGateway $easypaisa,
        CardGateway $card,
    ) {
        $this->gateways = [
            'cod' => $cod,
            'jazzcash' => $jazzCash,
            'easypaisa' => $easypaisa,
            'card' => $card,
        ];
    }

    public function initiateForOrder(Order $order): Payment
    {
        $gateway = $this->gatewayFor($order->payment_method);

        return DB::transaction(function () use ($order, $gateway) {
            $payment = Payment::create([
                'order_id' => $order->id,
                'method' => $order->payment_method,
                'status' => 'pending',
                'amount' => $order->total,
            ]);

            PaymentTransaction::create([
                'payment_id' => $payment->id,
                'event_type' => 'initiated',
            ]);

            $result = $gateway->initiate($order, $payment);

            $payment->update([
                'status' => $result['status'],
                'gateway_reference' => $result['gateway_reference'] ?? null,
            ]);

            PaymentTransaction::create([
                'payment_id' => $payment->id,
                'event_type' => $result['success'] ? 'confirmed' : 'failed',
                'raw_payload' => $result,
            ]);

            $order->update(['payment_status' => $result['status'] === 'paid' ? 'paid' : 'pending']);

            return $payment->fresh();
        });
    }

    public function handleCallback(Payment $payment, array $payload): array
    {
        $gateway = $this->gatewayFor($payment->method);

        PaymentTransaction::create([
            'payment_id' => $payment->id,
            'event_type' => 'webhook_received',
            'raw_payload' => $payload,
        ]);

        $result = $gateway->handleCallback($payload);

        $payment->update(['status' => $result['status']]);
        $payment->order->update(['payment_status' => $result['status'] === 'paid' ? 'paid' : $payment->order->payment_status]);

        PaymentTransaction::create([
            'payment_id' => $payment->id,
            'event_type' => $result['success'] ? 'confirmed' : 'failed',
            'raw_payload' => $result,
        ]);

        return $result;
    }

    public function markPaid(Payment $payment): Payment
    {
        $payment->update(['status' => 'paid']);
        $payment->order->update(['payment_status' => 'paid']);

        PaymentTransaction::create([
            'payment_id' => $payment->id,
            'event_type' => 'confirmed',
        ]);

        return $payment->fresh();
    }

    public function supportsOnlinePayment(string $method): bool
    {
        return $this->gatewayFor($method)->supportsRealPayment();
    }

    private function gatewayFor(string $method): PaymentGatewayInterface
    {
        return $this->gateways[$method] ?? throw new \InvalidArgumentException("Unknown payment method: {$method}");
    }
}
