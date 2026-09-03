<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\Payment;

/**
 * STUB — real JazzCash Mobile Account / Wallet API integration pending
 * merchant credentials (config('commerce.payment_gateways.jazzcash')).
 * Contract-complete so CheckoutService/PaymentService never need to change
 * when the real integration lands; only this class's internals do.
 */
class JazzCashGateway implements PaymentGatewayInterface
{
    public function initiate(Order $order, Payment $payment): array
    {
        return [
            'success' => true,
            'status' => 'pending',
            'gateway_reference' => 'STUB-JAZZCASH-'.$payment->id,
            'message' => 'JazzCash integration pending merchant credentials — payment marked pending.',
        ];
    }

    public function handleCallback(array $payload): array
    {
        return [
            'success' => false,
            'status' => 'failed',
            'message' => 'JazzCash callback handling not yet implemented.',
        ];
    }

    public function method(): string
    {
        return 'jazzcash';
    }

    public function supportsRealPayment(): bool
    {
        return false;
    }
}
