<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout\Headless;

/**
 * What the client needs after a start: the contract and the token that
 * authorise its return / cancel calls, the order number already drawn, and
 * how to render the PSP step.
 *
 * @since 3.0.0
 */
final readonly class HeadlessStartResult
{
    public function __construct(
        public string $contractId,
        public string $contractToken,
        public string $providerName,
        public ?string $orderNumber,
        public ?string $redirectUrl,
        public ?string $clientSecret,
        public string $renderMode,
    ) {
    }
}
