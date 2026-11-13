<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\RateLimit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;

/**
 * Doctrine DBAL-backed rate-limit store for the validation endpoint and the providers' webhook limiters.
 *
 * Reuses `oe_payments_idempotency` (OXSTATUS = 'rate_limit', count in OXRESULT); the key prefixes (`validate:`,
 * `{provider}:webhook:`) keep it apart from the payment idempotency keys.
 *
 * Sprint 29 (VRL, Mythos O-08):
 * - One atomic statement (INSERT … ON DUPLICATE KEY UPDATE on UNIQUE(OXKEY)). The former find-then-insert let two
 *   concurrent first calls collide on the key; the loser answered 0, i.e. "under the limit". An expired row of the
 *   same key is taken over and starts again at 1.
 * - A new row clears expired rows: `IdempotencyRepositoryInterface::deleteExpired()`, the table's one purge. Before,
 *   nothing removed a rate-limit row, so the table grew with every visitor and minute.
 * - Database errors propagate. A limiter that cannot count fails the request; it no longer lets it through.
 */
class DoctrineRateLimitStore implements RateLimitStoreInterface
{
    private const TABLE = 'oe_payments_idempotency';
    private const STATUS = 'rate_limit';

    /** MySQL's affected-rows answer for INSERT … ON DUPLICATE KEY UPDATE when it inserted (2 = updated). */
    private const INSERTED = 1;

    public function __construct(
        private readonly Connection $connection,
        private readonly IdempotencyRepositoryInterface $records,
    ) {
    }

    public function increment(string $key, int $ttlSeconds): int
    {
        $now = new DateTimeImmutable();
        if ($this->upsert($key, $now, $now->modify('+' . $ttlSeconds . ' seconds')) === self::INSERTED) {
            $this->records->deleteExpired();
        }

        return (int) $this->connection->fetchOne(
            'SELECT OXRESULT FROM ' . self::TABLE . ' WHERE OXKEY = :key',
            ['key' => $key],
        );
    }

    /**
     * OXRESULT is assigned before OXEXPIRES: MySQL evaluates the assignments left to right, so the expiry test of
     * the count still sees the old expiry.
     */
    private function upsert(string $key, DateTimeImmutable $now, DateTimeImmutable $expires): int
    {
        return (int) $this->connection->executeStatement(
            'INSERT INTO ' . self::TABLE
            . ' (OXID, OXKEY, OXORDERID, OXOPERATION, OXRESULT, OXSTATUS, OXCREATED, OXEXPIRES)'
            . " VALUES (:id, :key, '', :status, '1', :status, :now, :expires)"
            . ' ON DUPLICATE KEY UPDATE'
            . " OXRESULT = IF(OXEXPIRES <= :now, '1', CAST(OXRESULT AS UNSIGNED) + 1),"
            . ' OXEXPIRES = IF(OXEXPIRES <= :now, :expires, OXEXPIRES)',
            [
                'id' => md5($key),
                'key' => $key,
                'status' => self::STATUS,
                'now' => $now->format('Y-m-d H:i:s'),
                'expires' => $expires->format('Y-m-d H:i:s'),
            ],
        );
    }
}
