<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Adapter;

/**
 * The order ends the shared way ({@see LinkedOrderEndingInterface}): an unpaid order is stornoed,
 * its stock and its vouchers come back. Setting OXTRANSSTATUS alone left the coupon used for good
 * and hid the order from the not-finished cleanup.
 */
final class OxidContractLinkedOrderUpdater implements ContractLinkedOrderUpdaterInterface
{
    public function __construct(
        private readonly LinkedOrderEndingInterface $ending
    ) {
    }

    public function markCancelled(string $orderId): void
    {
        $this->ending->end($orderId, LinkedOrderEndingInterface::STATUS_CANCELLED);
    }

    /** The reason is part of the port for callers that log it; the ending itself does not need it. */
    public function markFailed(string $orderId, string $reason): void
    {
        $this->ending->end($orderId, LinkedOrderEndingInterface::STATUS_FAILED);
    }
}
