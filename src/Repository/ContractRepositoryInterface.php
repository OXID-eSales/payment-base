<?php

declare(strict_types=1);

namespace OxidEsales\PaymentComponent\Repository;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;

interface ContractRepositoryInterface
{
    public function save(PaymentContractInterface $contract): void;

    public function findById(string $id): ?PaymentContractInterface;

    public function findByProviderOrderId(string $providerOrderId): ?PaymentContractInterface;

    /**
     * @return array<int, PaymentContractInterface>
     */
    public function findByUserId(string $userId): array;

    public function findActiveByUserId(string $userId): ?PaymentContractInterface;

    /**
     * @return array<int, PaymentContractInterface>
     */
    public function findExpired(): array;
}
