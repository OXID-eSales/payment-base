<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Payment;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\AdminPaymentOperation;
use OxidEsales\PaymentBase\GraphQL\Admin\Service\PaymentRefusal;

/**
 * One kind of money operation (capture, refund, void): the right it needs, whether it moves money, and what it does
 * once the {@see MoneyOperationRunner} holds the order lock and the idempotency claim: policy, gateway, ledger.
 *
 * @since 1.7.0
 */
interface MoneyOperationInterface
{
    /** One of the {@see AdminPaymentOperation} kinds. */
    public function kind(): string;

    /** The admin right the operation demands. */
    public function right(): string;

    public function movesMoney(): bool;

    /**
     * @param string $idempotencyKey stable for every attempt of the same admin call; the PSP gets it too
     *
     * @return PaymentRefusal|null null: the PSP did it and the ledger booked it. A refusal: nothing was booked and the
     *                             call may be tried again. Anything thrown means the outcome is not known.
     */
    public function perform(PaymentContractInterface $contract, AdminPaymentOperation $operation, string $idempotencyKey): ?PaymentRefusal;
}
