<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Adapter\Response;

/**
 * Normalized response for saved payment method (vaulting).
 *
 * Represents a stored payment method that can be reused for future payments.
 *
 * @since 1.0.0
 */
readonly class PaymentMethodResponse
{
    /**
     * @param string $paymentMethodId Provider's payment method ID
     * @param string|null $customerId Provider's customer ID this payment method belongs to (null if not attached)
     * @param string $type Payment method type ('card', 'sepa_debit', 'paypal', etc.)
     * @param array<string, mixed> $details Payment method details (last4, brand, exp_month, exp_year, etc.)
     * @param bool $isDefault Whether this is the default payment method
     * @param \DateTimeInterface $createdAt Creation timestamp
     * @param array<string, mixed> $providerData Raw provider-specific data
     * @param array<string, mixed> $metadata Metadata
     */
    public function __construct(
        public string $paymentMethodId,
        public ?string $customerId,
        public string $type,
        public array $details,
        public bool $isDefault = false,
        public \DateTimeInterface $createdAt = new \DateTimeImmutable(),
        public array $providerData = [],
        public array $metadata = [],
    ) {
    }
}
