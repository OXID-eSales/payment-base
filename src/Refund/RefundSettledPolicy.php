<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Refund;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Service\StockRestorationServiceInterface;

/**
 * Restock only on a succeeded refund that leaves nothing refundable. Asking again after the restock is
 * harmless: the restoration service does its work once per order.
 */
final class RefundSettledPolicy implements RefundSettledPolicyInterface
{
    public function __construct(
        private readonly StockRestorationServiceInterface $stockRestoration,
    ) {
    }

    public function settle(PaymentContractInterface $contract, bool $refundSucceeded): bool
    {
        $orderId = $contract->getOrderId();
        if (!$refundSucceeded || $orderId === null || $orderId === '' || !$contract->isFullyRefunded()) {
            return false;
        }

        $this->stockRestoration->restoreForFullRefund($orderId);

        return true;
    }
}
