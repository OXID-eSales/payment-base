<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Checkout;

use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;

/**
 * Reads the open attempt from the session's registry and answers with its
 * early order, but only while that attempt can still be paid.
 *
 * Why it exists (OPC-175): the early order marks the basket vouchers as used
 * before the customer pays. Core then refuses those vouchers in every later
 * basket recalculation and drops the coupon. The Voucher extension asks this
 * service whether a used row belongs to the session's own open attempt.
 *
 * Settled (committed) and ended (terminal) attempts do not answer: their
 * voucher use is final, and core's rules apply again.
 *
 * @since OPC-175
 */
class OpenCheckoutAttemptOrder implements OpenCheckoutAttemptOrderInterface
{
    public function __construct(
        private readonly OpenCheckoutAttemptRegistryInterface $openAttempts,
        private readonly ContractRepositoryInterface $contractRepository
    ) {
    }

    public function orderId(): ?string
    {
        $contractId = $this->openAttempts->peek();
        if ($contractId === null) {
            return null;
        }

        $contract = $this->contractRepository->findById($contractId);
        if ($contract === null) {
            return null;
        }

        $state = $contract->getState();
        if ($state->isTerminal() || $state->isCommitted()) {
            return null;
        }

        $orderId = $contract->getOrderId();

        return $orderId === null || $orderId === '' ? null : $orderId;
    }
}
