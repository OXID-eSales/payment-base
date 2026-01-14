<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Adapter\Request;

/**
 * Request for capturing a previously authorized payment.
 *
 * Step 2 of two-step authorization flow (authorize → capture).
 * This is identical to CapturePaymentRequest in most providers.
 *
 * Provider-agnostic - adapters translate to provider-specific formats.
 *
 * @since 1.0.0
 */
readonly class CaptureAuthorizationRequest
{
    /**
     * @param string $authorizationId Provider's authorization ID
     * @param float|null $amount Amount to capture (null = full capture)
     * @param array<string, mixed> $metadata Additional metadata
     */
    public function __construct(
        public string $authorizationId,
        public ?float $amount = null,
        public array $metadata = [],
    ) {
    }
}
