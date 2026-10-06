<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

/**
 * What a `<provider>CheckoutStart` mutation knows.
 *
 * @param string $uiMode how the client wants to render the PSP step: `hosted` (redirect), `embedded`, `custom`
 *
 * @since 3.0.0
 */
final readonly class HeadlessStartRequest
{
    public function __construct(
        public string $userId,
        public string $basketId,
        public bool $confirmTermsAndConditions,
        public string $returnUrl,
        public ?string $cancelUrl = null,
        public string $uiMode = 'hosted',
    ) {
    }
}
