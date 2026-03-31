<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Adapter;

/**
 * Interface for payment handler adapters.
 * Each payment provider (Stripe, PayPal, etc.) implements this interface.
 */
interface PaymentHandlerInterface
{
    /**
     * Get unique handler identifier (e.g., 'stripe', 'paypal')
     */
    public function getId(): string;

    /**
     * Get human-readable handler name
     */
    public function getName(): string;

    /**
     * Check if this handler supports the given payment method ID
     */
    public function supports(string $paymentMethodId): bool;

    /**
     * Process payment and create contract
     *
     * @param PaymentContextInterface $context Payment context with basket, user, etc.
     * @return PaymentHandlerResult Result with contract ID, client secret, etc.
     */
    public function processPayment(PaymentContextInterface $context): PaymentHandlerResult;

    /**
     * Confirm payment with provider
     *
     * @param string $transactionId Provider transaction ID
     * @return PaymentHandlerResult Result with confirmation status
     */
    public function confirmPayment(string $transactionId): PaymentHandlerResult;

    /**
     * Get required frontend configuration (e.g., publishable keys)
     *
     * @return array<string, mixed> Configuration array
     */
    public function getFrontendConfig(): array;
}
