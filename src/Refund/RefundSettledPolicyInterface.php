<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Refund;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

/**
 * VR-21 F3 (D-1) - what a booked provider refund does to the order's stock. One rule for every path that books
 * a refund (admin action, webhook, intent): the stock goes back when the refund SUCCEEDED and nothing is left
 * to refund. A partial refund keeps the goods sold; a pending one may still fail.
 *
 * @since 1.7.0
 */
interface RefundSettledPolicyInterface
{
    /**
     * @param bool $refundSucceeded the provider's final word on the refund just booked (false: still pending)
     *
     * @return bool true when the order's stock was handed back to the restoration service
     */
    public function settle(PaymentContractInterface $contract, bool $refundSucceeded): bool;
}
