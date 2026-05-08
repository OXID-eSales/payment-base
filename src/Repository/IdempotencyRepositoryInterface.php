<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use OxidEsales\PaymentBase\Contract\IdempotencyRecord;

/**
 * Repository interface for idempotency records.
 *
 * Sprint 42: Idempotency implementation.
 *
 * @since 1.0.0
 */
interface IdempotencyRepositoryInterface
{
    public function save(IdempotencyRecord $record): void;

    public function findByKey(string $key): ?IdempotencyRecord;

    /**
     * Delete expired records. Returns count of deleted rows.
     */
    public function deleteExpired(): int;
}
