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

    public function findById(string $id): ?PaymentContractInterface;

    public function findByProviderOrderId(string $providerOrderId): ?PaymentContractInterface;

    /**
     * @return array<int, PaymentContractInterface>
     */
    public function findByUserId(string $userId): array;

    public function findActiveByUserId(string $userId): ?PaymentContractInterface;

    /**
     * Sprint 15 / S3: the newest contract of this user that is still open
     * (NOT_FINISHED or PENDING - no money taken) and carries `basket_id` =
     * $basketId in its metadata; the headless "previous attempt for this
     * basket" when no session registry can answer. Null when there is none.
     */
    public function findOpenByUserAndBasketId(string $userId, string $basketId): ?PaymentContractInterface;

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
     * @param int|null $limit cap the batch, or null for no cap. The sweep runs
     *                        inline in a provider's webhook request, where an
     *                        unbounded backlog is paid for out of that request's
     *                        response time.
     *
     * @return array<int, PaymentContractInterface>
     */
    public function findStaleNotFinished(int $minutesOld, ?int $limit = null): array;
}
