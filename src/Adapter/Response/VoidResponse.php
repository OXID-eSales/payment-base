<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter\Response;

/**
 * Normalized response from voiding (cancelling) a payment authorization.
 *
 * Provider-agnostic response for void/cancel operations.
 *
 * @since 1.0.0
 */
readonly class VoidResponse
{
    /**
     * @param string $providerPaymentId Provider's payment ID
     * @param string $status Void status ('succeeded', 'failed')
     * @param \DateTimeInterface $voidedAt Timestamp when void occurred
     * @param string|null $reason Cancellation reason
     * @param array<string, mixed> $providerData Raw provider-specific data
     * @param array<string, mixed> $metadata Metadata
     */
    public function __construct(
        public string $providerPaymentId,
        public string $status,
        public \DateTimeInterface $voidedAt,
        public ?string $reason = null,
        public array $providerData = [],
        public array $metadata = [],
    ) {
    }
}
