<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Service;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

interface ContractServiceInterface extends ServiceInterface
{
    /**
     * Create a new payment contract for a user with their basket.
     *
     * @param string $userId User identifier
     * @param object $basket Basket object containing order items
     * @param array<int, string> $conditionTypes Optional condition types to add to contract
     * @return PaymentContractInterface Created payment contract
     */
    public function createContract(
        string $userId,
        object $basket,
        array $conditionTypes = []
    ): PaymentContractInterface;

    /**
     * Find an active (non-terminal) contract for a user.
     *
     * @param string $userId User identifier
     * @return PaymentContractInterface|null Active contract or null if none found
     */
    public function findActiveContractByUser(string $userId): ?PaymentContractInterface;

}
