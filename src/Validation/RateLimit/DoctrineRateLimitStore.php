<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Validation\RateLimit;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;

/**
 * Doctrine DBAL-backed rate-limit store.
 *
 * Reuses the `oe_payments_idempotency` table with a `validate:` key prefix.
 * The counter value is stored as a string in `OXRESULT`; `OXSTATUS` = 'rate_limit'.
 *
 * Decision rationale: The idempotency table already provides a TTL-keyed
 * key/value store with Doctrine support. No new table or migration is needed;
 * the `validate:` prefix ensures no collision with webhook idempotency keys.
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
final class DoctrineRateLimitStore implements RateLimitStoreInterface
{
    private const TABLE = 'oe_payments_idempotency';
    private const STATUS = 'rate_limit';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function increment(string $key, int $ttlSeconds): int
    {
        $now = new DateTimeImmutable();
        $expires = $now->modify("+{$ttlSeconds} seconds");

        try {
            $existing = $this->findRow($key);

            if ($existing === null || $this->isExpired($existing, $now)) {
                $this->insertRow($key, 1, $expires);

                return 1;
            }

            $newCount = (int) ($existing['OXRESULT'] ?? 0) + 1;
            $this->updateRow($key, $newCount);

            return $newCount;
        } catch (Exception) {
            return 0;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRow(string $key): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ' . self::TABLE . ' WHERE OXKEY = :key AND OXSTATUS = :status',
            ['key' => $key, 'status' => self::STATUS],
        );

        return $row !== false ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isExpired(array $row, DateTimeImmutable $now): bool
    {
        $expires = $row['OXEXPIRES'] ?? null;

        if (!is_string($expires)) {
            return true;
        }

        return new DateTimeImmutable($expires) <= $now;
    }

    private function insertRow(string $key, int $count, DateTimeImmutable $expires): void
    {
        $this->connection->insert(self::TABLE, [
            'OXID' => md5($key . microtime()),
            'OXKEY' => $key,
            'OXORDERID' => '',
            'OXOPERATION' => 'rate_limit',
            'OXRESULT' => (string) $count,
            'OXSTATUS' => self::STATUS,
            'OXCREATED' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'OXEXPIRES' => $expires->format('Y-m-d H:i:s'),
        ]);
    }

    private function updateRow(string $key, int $count): void
    {
        $this->connection->update(
            self::TABLE,
            ['OXRESULT' => (string) $count],
            ['OXKEY' => $key, 'OXSTATUS' => self::STATUS],
        );
    }
}
