<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use DateTimeImmutable;
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
     * Take the record's key if nobody holds it (VR-22): insert, or take over a record whose
     * expiry is before `$now` (ran out, or released). One statement each - two concurrent
     * claims of one key cannot both win.
     */
    public function claim(IdempotencyRecord $record, DateTimeImmutable $now): bool;

    /**
     * Delete expired records. Returns count of deleted rows.
     */
    public function deleteExpired(): int;
}
