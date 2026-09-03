<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\Payment;

/**
 * STUB — real Easypaisa API integration pending merchant credentials
 * (config('commerce.payment_gateways.easypaisa')). Contract-complete so
 * CheckoutService/PaymentService never need to change when the real
 * integration lands; only this class's internals do.
 */
class EasypaisaGateway implements PaymentGatewayInterface
{
    public function initiate(Order $order, Payment $payment): array
    {
        return [
            'success' => true,
            'status' => 'pending',
            'gateway_reference' => 'STUB-EASYPAISA-'.$payment->id,
            'message' => 'Easypaisa integration pending merchant credentials — payment marked pending.',
        ];
    }

    public function handleCallback(array $payload): array
    {
        return [
            'success' => false,
            'status' => 'failed',
            'message' => 'Easypaisa callback handling not yet implemented.',
        ];
    }

    public function method(): string
    {
        return 'easypaisa';
    }

    public function supportsRealPayment(): bool
    {
        return false;
    }
}
