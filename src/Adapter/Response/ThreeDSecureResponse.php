<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Adapter\Response;

/**
 * Normalized response for 3D Secure authentication result.
 *
 * Indicates whether Strong Customer Authentication (SCA) was successful.
 *
 * @since 1.0.0
 */
readonly class ThreeDSecureResponse
{
    /**
     * @param string $paymentId Payment ID that underwent 3DS
     * @param bool $authenticated Whether 3DS authentication was successful
     * @param string $status Authentication status ('authenticated', 'failed', 'pending', 'not_required')
     * @param string|null $redirectUrl URL to redirect for authentication challenge
     * @param string|null $authenticationId Provider's 3DS authentication ID
     * @param array<string, mixed> $providerData Raw provider-specific data
     */
    public function __construct(
        public string $paymentId,
        public bool $authenticated,
        public string $status,
        public ?string $redirectUrl = null,
        public ?string $authenticationId = null,
        public array $providerData = [],
    ) {
    }
}
