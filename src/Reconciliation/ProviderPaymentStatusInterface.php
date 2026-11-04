<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Reconciliation;

/**
 * Port a provider implements (tag `oe.payment.paid_status_provider`) so the shared reconciliation
 * can ask it for the status of a payment.
 */
interface ProviderPaymentStatusInterface
{
    /** Provider name as stored on the contract ({@see \OxidEsales\PaymentBase\Contract\PaymentContractInterface::getProvider()}). */
    public function provider(): string;

    /** Asks the provider's API; may throw when the provider cannot be reached. */
    public function statusOf(string $providerOrderId): ProviderPaymentStatus;
}
