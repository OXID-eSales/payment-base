<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Tests\Unit\GraphQL\Admin\Support;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Driver\Exception as DriverException;
use OxidEsales\PaymentBase\Contract\IdempotencyRecord;
use OxidEsales\PaymentBase\Repository\IdempotencyRepositoryInterface;

/**
 * The idempotency table in memory. `$racingInsertFor` makes the next save of that key fail the way the
 * unique index of OXKEY does when another request inserted it between our read and our insert.
 */
final class InMemoryIdempotency implements IdempotencyRepositoryInterface
{
    /** @var array<string, IdempotencyRecord> */
    public array $records = [];
    public ?string $racingInsertFor = null;

    public function save(IdempotencyRecord $record): void
    {
        if ($this->racingInsertFor === $record->getKey()) {
            $this->racingInsertFor = null;
            throw new UniqueConstraintViolationException(
                new class ('duplicate key') extends \Exception implements DriverException {
                    public function getSQLState(): ?string
                    {
                        return '23000';
                    }
                },
                null,
            );
        }
        $this->records[$record->getKey()] = $record;
    }

    public function findByKey(string $key): ?IdempotencyRecord
    {
        return $this->records[$key] ?? null;
    }

    /**
     * `racingInsertFor`: another process takes the key between our look-up and our claim.
     */
    public function claim(IdempotencyRecord $record, DateTimeImmutable $now): bool
    {
        if ($this->racingInsertFor === $record->getKey()) {
            $this->racingInsertFor = null;

            return false;
        }
        $held = $this->records[$record->getKey()] ?? null;
        if ($held !== null && $held->getExpiresAt() >= $now) {
            return false;
        }
        $this->records[$record->getKey()] = $record;

        return true;
    }

    public function deleteExpired(): int
    {
        return 0;
    }

    public function deleteExpiredBatch(): int
    {
        return 0;
    }
}
