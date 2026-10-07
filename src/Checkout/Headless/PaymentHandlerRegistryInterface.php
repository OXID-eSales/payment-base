<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

use OxidEsales\PaymentBase\Adapter\PaymentHandlerInterface;

/**
 * The provider modules' contract-first payment handlers (tag
 * `oe.payment.handler`, implementing ContractFirstPaymentHandlerInterface),
 * looked up by payment id or by provider.
 *
 * Sprint 15 / S6 (GRAPH-QL). Every provider already registers its handler
 * for the one-page checkout; the headless checkout reuses it: "Place Order"
 * for a basket paying with X is `handler(X)->processPayment()`.
 *
 * @since 3.0.0
 */
interface PaymentHandlerRegistryInterface
{
    public function forPaymentMethod(string $paymentMethodId): ?PaymentHandlerInterface;

    public function forProvider(string $providerName): ?PaymentHandlerInterface;

    /**
     * Whether this payment id belongs to a contract-first provider, i.e. is
     * paid through the provider's checkout mutations and never through core
     * `placeOrder`.
     */
    public function isContractFirst(string $paymentMethodId): bool;
}
