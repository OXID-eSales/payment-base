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
     * Delete every expired record (in batches); returns the number deleted. A manual tool since Sprint 29 (VRL):
     * the table cleans itself through deleteExpiredBatch().
     */
    public function deleteExpired(): int;

    /**
     * Sprint 29 (VRL): delete one bounded batch of expired records; returns the number deleted. Runs after every
     * insert into the table (save, claim, rate-limit store), so it stays at its live size without cron. An expired
     * record means "free" to every reader, so deleting it changes nothing for them.
     */
    public function deleteExpiredBatch(): int;
}
