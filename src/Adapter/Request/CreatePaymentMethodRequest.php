<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter\Request;

/**
 * Request for creating and saving a payment method (vaulting).
 *
 * Allows customers to save payment methods (cards, bank accounts)
 * for future use without re-entering details.
 *
 * Common use cases:
 * - Saved cards for one-click checkout
 * - Recurring payments/subscriptions
 * - Account-on-file for digital wallets
 *
 * Provider-agnostic - adapters translate to provider-specific formats.
 *
 * @since 1.0.0
 */
readonly class CreatePaymentMethodRequest
{
    /**
     * @param string $paymentMethod Generic payment method type ('card', 'sepa_debit', etc.)
     * @param string|null $customerId Provider's customer ID to attach payment method to (null if not attaching)
     * @param array<string, mixed> $paymentMethodData Provider-specific payment method data
     * @param array<string, string>|null $billingAddress Billing address
     * @param array<string, mixed> $metadata Additional metadata
     */
    public function __construct(
        public string $paymentMethod,
        public ?string $customerId = null,
        public array $paymentMethodData = [],
        public ?array $billingAddress = null,
        public array $metadata = [],
    ) {
    }
}
