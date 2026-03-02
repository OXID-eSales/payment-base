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
     * Find contract by OXID order ID.
     */
    public function findByOrderId(string $orderId): ?PaymentContractInterface;

    /**
     * @return array<int, PaymentContractInterface>
     */
    public function findExpired(): array;

    /**
     * Find non-terminal, non-committed contracts older than the given threshold.
     *
     * Used to garbage-collect abandoned checkouts (contracts stuck in
     * draft/not_finished/pending states with NOT_FINISHED orders).
     *
     * @return array<int, PaymentContractInterface>
     */
    public function findStaleNotFinished(int $minutesOld): array;
}
