<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support;

use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;

/**
 * Keeps saved contracts by id, so a test sees what a (fake) provider action did to them.
 */
final class InMemoryContracts implements ContractRepositoryInterface
{
    /** @var array<string, PaymentContractInterface> */
    private array $contracts = [];

    public function save(PaymentContractInterface $contract): void
    {
        $this->contracts[(string) $contract->getId()] = $contract;
    }

    public function findById(string $id): ?PaymentContractInterface
    {
        return $this->contracts[$id] ?? null;
    }

    public function findByOrderId(string $orderId): ?PaymentContractInterface
    {
        foreach ($this->contracts as $contract) {
            if ($contract->getOrderId() === $orderId) {
                return $contract;
            }
        }

        return null;
    }

    public function findByProviderOrderId(string $providerOrderId): ?PaymentContractInterface
    {
        return null;
    }

    public function findByUserId(string $userId): array
    {
        return [];
    }

    public function findActiveByUserId(string $userId): ?PaymentContractInterface
    {
        return null;
    }

    public function findExpired(): array
    {
        return [];
    }

    public function findStaleNotFinished(int $minutesOld, ?int $limit = null): array
    {
        return [];
    }
}
