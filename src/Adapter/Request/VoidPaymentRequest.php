<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter\Request;

/**
 * Request for voiding (cancelling) an authorized but not captured payment.
 *
 * Used to release funds that were reserved during authorization.
 * Only applicable to uncaptured authorizations.
 *
 * Provider-agnostic - adapters translate to provider-specific formats.
 *
 * @since 1.0.0
 */
readonly class VoidPaymentRequest
{
    /**
     * @param string $providerPaymentId Provider's payment/authorization ID
     * @param string|null $reason Cancellation reason
     * @param array<string, mixed> $metadata Additional metadata
     */
    public function __construct(
        public string $providerPaymentId,
        public ?string $reason = null,
        public array $metadata = [],
    ) {
    }
}
