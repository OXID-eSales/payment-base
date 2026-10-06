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
 * @param array<string, mixed> $providerOptions provider-specific hints the mutation accepts (Mollie: `mollieMethod`),
 *        handed to the handler as PaymentContext metadata; the headless keys (`basketId`, `uiMode`, `headless`,
 *        `sessionId`) cannot be overridden
 *
 * @since 3.0.0
 */
final readonly class HeadlessStartRequest
{
    /**
     * @param array<string, mixed> $providerOptions
     */
    public function __construct(
        public string $userId,
        public string $basketId,
        public bool $confirmTermsAndConditions,
        public string $returnUrl,
        public ?string $cancelUrl = null,
        public string $uiMode = 'hosted',
        public ?string $paymentId = null,
        public array $providerOptions = [],
    ) {
    }
}
