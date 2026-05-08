<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter\Request;

/**
 * Request for initiating 3D Secure / Strong Customer Authentication (SCA).
 *
 * Required by PSD2 regulation in Europe for certain transactions.
 * Adds additional authentication layer to confirm customer identity.
 *
 * Flow:
 * 1. Create payment with 3DS requirement
 * 2. Customer redirected to 3DS challenge (bank authentication)
 * 3. Customer completes authentication
 * 4. Return to shop with authentication result
 *
 * Provider-agnostic - adapters translate to provider-specific formats.
 *
 * @since 1.0.0
 */
readonly class ThreeDSecureRequest
{
    /**
     * @param string $paymentId Payment/authorization ID requiring 3DS
     * @param string|null $returnUrl Optional URL to return after 3DS authentication
     * @param array<string, mixed> $metadata Additional metadata
     */
    public function __construct(
        public string $paymentId,
        public ?string $returnUrl = null,
        public array $metadata = [],
    ) {
    }
}
