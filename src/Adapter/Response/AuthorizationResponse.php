<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter\Response;

/**
 * Normalized response from authorizing a payment (two-step auth).
 *
 * Represents reserved funds that can later be captured or voided.
 *
 * @since 1.0.0
 */
readonly class AuthorizationResponse
{
    /**
     * @param string $authorizationId Provider's authorization ID
     * @param string $providerPaymentId Provider's payment ID (may be same as authorization ID)
     * @param string $status Authorization status ('authorized', 'pending', 'failed', 'expired')
     * @param float $amount Authorized amount in major units
     * @param string $currency ISO 4217 currency code
     * @param \DateTimeInterface $authorizedAt Authorization timestamp
     * @param \DateTimeInterface $expiresAt Authorization expiration timestamp
     * @param bool $requiresAction Whether additional customer action is required
     * @param string|null $clientSecret Client secret for frontend confirmation
     * @param string|null $redirectUrl Redirect URL for authentication
     * @param array<string, mixed> $providerData Raw provider-specific data
     * @param array<string, mixed> $metadata Metadata
     */
    public function __construct(
        public string $authorizationId,
        public string $providerPaymentId,
        public string $status,
        public float $amount,
        public string $currency,
        public \DateTimeInterface $authorizedAt,
        public \DateTimeInterface $expiresAt,
        public bool $requiresAction = false,
        public ?string $clientSecret = null,
        public ?string $redirectUrl = null,
        public array $providerData = [],
        public array $metadata = [],
    ) {
    }
}
