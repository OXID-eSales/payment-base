<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\GraphQL\Admin\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Contract\Transaction;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Repository\TransactionRepositoryInterface;

/**
 * Reads the contract behind an order and its transactions into the admin API's read model. The amounts come
 * from the contract's capture/refund tracker, which every provider module maintains.
 *
 * @since 1.5.0
 */
final class PaymentContractDetailsReader implements PaymentContractDetailsReaderInterface
{
    public function __construct(
        private readonly ContractRepositoryInterface $contracts,
        private readonly TransactionRepositoryInterface $transactions,
    ) {
    }

    public function findByOrderId(string $orderId): ?PaymentContractDetails
    {
        $contract = $this->contracts->findByOrderId($orderId);
        if ($contract === null) {
            return null;
        }

        return new PaymentContractDetails(
            contractId: (string) $contract->getId(),
            provider: (string) $contract->getProvider(),
            state: $contract->getStateValue(),
            amountAuthorised: $contract->getAmount(),
            amountCaptured: $contract->getCapturedAmount() ?? 0.0,
            amountRefunded: $contract->getRefundedAmount() ?? 0.0,
            currency: $contract->getCurrency(),
            transactions: $this->transactionsOf($contract),
        );
    }

    /**
     * @return list<PaymentTransactionDetails>
     */
    private function transactionsOf(PaymentContractInterface $contract): array
    {
        return array_values(array_map(
            static fn (Transaction $transaction): PaymentTransactionDetails => new PaymentTransactionDetails(
                id: $transaction->getId(),
                type: $transaction->getType(),
                status: $transaction->getStatus(),
                amount: $transaction->getAmount(),
                createdAt: $transaction->getCreatedAt(),
                providerReference: $transaction->getTransactionId(),
            ),
            $this->transactions->findByContractId((string) $contract->getId()),
        ));
    }
}
