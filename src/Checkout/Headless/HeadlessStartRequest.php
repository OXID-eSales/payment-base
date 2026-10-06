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
 * @param string|null $paymentId the provider's payment id when the mutation is provider-specific
 *        (`stripeCheckoutStart` knows it is Stripe); used when the basket row carries no payment, refused when the
 *        row names another one
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
        public ?string $paymentId = null,
    ) {
    }
}
