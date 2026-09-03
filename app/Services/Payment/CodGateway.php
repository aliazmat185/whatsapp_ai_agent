<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\Payment;

/**
 * Cash on Delivery — the only fully-functional gateway in v1. No external
 * call; payment is collected by the vendor/rider on delivery, so it stays
 * "pending" until the vendor manually marks it paid via the order panel.
 */
class CodGateway implements PaymentGatewayInterface
{
    public function initiate(Order $order, Payment $payment): array
    {
        return [
            'success' => true,
            'status' => 'pending',
            'gateway_reference' => null,
            'message' => 'Cash on delivery — payment collected on delivery.',
        ];
    }

    public function handleCallback(array $payload): array
    {
        return [
            'success' => false,
            'status' => 'failed',
            'message' => 'COD does not support callbacks.',
        ];
    }

    public function method(): string
    {
        return 'cod';
    }

    public function supportsRealPayment(): bool
    {
        return true;
    }
}
