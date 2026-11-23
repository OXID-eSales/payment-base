<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;

interface ContractRepositoryInterface
{
    /**
     * Persists the contract. MOL-17: the row carries a version; saving a copy that was loaded before
     * another writer saved the same contract throws {@see StaleContractException} - reload and decide,
     * never overwrite (the shopper's return leg and the PSP webhook race on the same contract).
     *
     * @throws StaleContractException
     */
    public function save(PaymentContractInterface $contract): void;

    /**
     * Sprint 147 (AW): for every finder below `null` means "no such contract" and nothing else. A
     * database error is not "not found" - a webhook handler would drop the provider's event - so it
     * leaves the finder as an exception.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function findById(string $id): ?PaymentContractInterface;

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function findByProviderOrderId(string $providerOrderId): ?PaymentContractInterface;

    /**
     * @throws \Doctrine\DBAL\Exception
     */
    public function findActiveByUserId(string $userId): ?PaymentContractInterface;

    /**
     * Find contract by OXID order ID.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function findByOrderId(string $orderId): ?PaymentContractInterface;

    /**
     * Find non-terminal, non-committed contracts older than the given threshold.
     *
     * Used to garbage-collect abandoned checkouts (contracts stuck in
     * draft/not_finished/pending states with NOT_FINISHED orders).
     *
     * @param int|null $limit cap the batch, or null for no cap. The sweep runs
     *                        inline in a provider's webhook request, where an
     *                        unbounded backlog is paid for out of that request's
     *                        response time.
     *
     * @return array<int, PaymentContractInterface>
     */
    public function findStaleNotFinished(int $minutesOld, ?int $limit = null): array;
}
