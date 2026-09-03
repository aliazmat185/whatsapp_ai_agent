<?php

namespace App\Services\Payment;

use App\Models\Order;
use App\Models\Payment;

/**
 * PLAN.md §7 Payment Requirements — modular gateway contract so a real
 * JazzCash/Easypaisa/Card SDK integration can be dropped in later without
 * touching CheckoutService/OrderService.
 */
interface PaymentGatewayInterface
{
    /**
     * Starts payment collection for an order. For COD this is a no-op that
     * immediately reports success; for real gateways this would call out to
     * the provider and return a redirect/session reference.
     *
     * @return array{success: bool, status: string, gateway_reference: ?string, message: ?string}
     */
    public function initiate(Order $order, Payment $payment): array;

    /**
     * Handles an inbound callback/webhook from the gateway confirming or
     * rejecting a payment. Not used by COD.
     *
     * @return array{success: bool, status: string, message: ?string}
     */
    public function handleCallback(array $payload): array;

    public function method(): string;

    /**
     * Whether this gateway can actually collect/confirm payment today, as
     * opposed to being a contract-complete stub awaiting real credentials.
     * Checkout must never let a customer "pay" through a gateway that can't
     * really take payment — see ToolRegistry::startCheckout().
     */
    public function supportsRealPayment(): bool;
}
