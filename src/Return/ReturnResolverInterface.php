<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Return;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\EventSystem\Event\EventContextInterface;

/**
 * Per-provider: given a contract + the request context, ask the PSP what
 * happened to its underlying payment intent / order, and return a
 * provider-neutral {@see ReturnResolution}.
 *
 * Resolvers are **pure data producers**. They do not transition the contract,
 * they do not save, they do not dispatch events. That work lives in shared
 * `payment-base` handlers (Sprint B: `ContractPendingTransitioner`,
 * `EarlyOrderCreationHandler`, `ContractCommitmentHandler`).
 *
 * PSP-specific inputs (Stripe's `checkoutSessionId`, PayPal's
 * `payPalOrderId`) arrive via the context so the interface stays uniform
 * across providers.
 */
interface ReturnResolverInterface
{
    public function resolve(
        PaymentContractInterface $contract,
        EventContextInterface $context,
    ): ReturnResolution;
}
