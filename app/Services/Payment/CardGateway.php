<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\Payment;

/**
 * STUB — real card processor integration (e.g. Stripe-compatible or a
 * local acquiring bank) pending merchant credentials
 * (config('commerce.payment_gateways.card')). Contract-complete so
 * CheckoutService/PaymentService never need to change when the real
 * integration lands; only this class's internals do.
 */
class CardGateway implements PaymentGatewayInterface
{
    public function initiate(Order $order, Payment $payment): array
    {
        return [
            'success' => true,
            'status' => 'pending',
            'gateway_reference' => 'STUB-CARD-'.$payment->id,
            'message' => 'Card payment integration pending merchant credentials — payment marked pending.',
        ];
    }

    public function handleCallback(array $payload): array
    {
        return [
            'success' => false,
            'status' => 'failed',
            'message' => 'Card gateway callback handling not yet implemented.',
        ];
    }

    public function method(): string
    {
        return 'card';
    }

    public function supportsRealPayment(): bool
    {
        return false;
    }
}
