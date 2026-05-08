<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter\Request;

/**
 * Request for reauthorizing an expired or expiring authorization.
 *
 * Authorizations typically expire after 7 days. This allows extending
 * or renewing the authorization if capture hasn't happened yet.
 *
 * Not all providers support reauthorization.
 *
 * Provider-agnostic - adapters translate to provider-specific formats.
 *
 * @since 1.0.0
 */
readonly class ReauthorizePaymentRequest
{
    /**
     * @param string $authorizationId Original authorization ID
     * @param float|null $amount New authorization amount (null = same as original)
     * @param array<string, mixed> $metadata Additional metadata
     */
    public function __construct(
        public string $authorizationId,
        public ?float $amount = null,
        public array $metadata = [],
    ) {
    }
}
