<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Admin\Panel;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * Immutable context passed to every {@see PaymentPanelProviderInterface}.
 *
 * Carries only what the admin panel needs to decide support and render:
 * the OXID order id, its payment-method key, and the resolved
 * {@see PaymentContractInterface} (or null if none exists yet).
 */
final readonly class PaymentPanelContext
{
    public function __construct(
        public string $orderId,
        public string $paymentType,
        public ?PaymentContractInterface $contract,
    ) {
    }

    public function getProviderName(): ?string
    {
        return $this->contract?->getProvider();
    }
}
