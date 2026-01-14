<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Adapter\Request;

/**
 * Request for voiding (cancelling) an authorization.
 *
 * Releases reserved funds without capturing them.
 * This is identical to VoidPaymentRequest in most providers.
 *
 * Provider-agnostic - adapters translate to provider-specific formats.
 *
 * @since 1.0.0
 */
readonly class VoidAuthorizationRequest
{
    /**
     * @param string $authorizationId Provider's authorization ID
     * @param string|null $reason Cancellation reason
     * @param array<string, mixed> $metadata Additional metadata
     */
    public function __construct(
        public string $authorizationId,
        public ?string $reason = null,
        public array $metadata = [],
    ) {
    }
}
